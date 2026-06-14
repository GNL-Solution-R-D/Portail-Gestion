<?php

/**
 * include/notifications.php
 *
 * Couche notifications côté serveur — parle au webhook n8n qui pilote la table
 * des notifications (1 ligne = 1 notification, rattachée à client_id).
 *
 * Aucune dépendance directe à la base : tout passe par n8n, exactement comme
 * data/domains_api.php. Toutes les fonctions sont préfixées « notif_ » (sauf
 * notify()) pour ne jamais entrer en collision avec data/domains_api.php.
 *
 * Deux usages :
 *   1) Création MANUELLE ou AUTOMATIQUE : appeler notify(...) depuis n'importe
 *      quel flux serveur (commande créée, facture émise, invitation équipe...).
 *   2) Lecture / marquage : utilisé par data/notifications_api.php (proxy navigateur).
 *
 * Contrat n8n (webhook unique, GET = lecture / POST = écriture) :
 *   GET  ?action=list&client_id=…&limit=20
 *        → [ {ligne}, ... ]   OU   { notifications:[...], unread:N }
 *   POST { action:'read',  client_id, id:"…" }   → { ok:true }
 *   POST { action:'read',  client_id, all:1 }    → { ok:true }   (tout marquer lu)
 *   POST { action:'create',client_id, type, title, message, link } → { row:{…} }
 *
 * Variables d'environnement :
 *   N8N_DATA_NOTIFICATION_URL  (def. https://api.gnl-solution.fr/webhook/data-notification)
 *   N8N_WEBHOOK_TOKEN          (jeton « Header Auth » du webhook, optionnel)
 */

declare(strict_types=1);

if (!function_exists('notif_getenv_non_empty')) {
    /** Variable d'environnement uniquement si définie ET non vide après trim. */
    function notif_getenv_non_empty(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}

if (!function_exists('notif_n8n_url')) {
    function notif_n8n_url(): string
    {
        return notif_getenv_non_empty('N8N_DATA_NOTIFICATION_URL')
            ?? 'https://api.gnl-solution.fr/webhook/portail_api';
    }
}

if (!function_exists('notif_n8n_token')) {
    function notif_n8n_token(): ?string
    {
        return notif_getenv_non_empty('N8N_WEBHOOK_TOKEN');
    }
}

if (!function_exists('notif_n8n_call')) {
    /**
     * Relaie un payload au webhook n8n et renvoie la réponse décodée.
     * En GET, le payload part en query string ; sinon en JSON.
     *
     * @return array{status:int, json:mixed, raw:string}
     */
    function notif_n8n_call(array $payload, string $method = 'POST'): array
    {
        $url     = notif_n8n_url();
        $token   = notif_n8n_token();
        $method  = strtoupper($method);
        $isGet   = ($method === 'GET');
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            // n8n « Header Auth » : adapter le nom d'en-tête à votre workflow.
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-GNL-Token: ' . $token;
        }

        $body = null;
        if ($isGet) {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . http_build_query($payload);
        } else {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (function_exists('curl_init')) {
            $opts = [
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
            ];
            if ($isGet) {
                $opts[CURLOPT_HTTPGET] = true;
            } else {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                $opts[CURLOPT_POSTFIELDS]    = $body;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                throw new RuntimeException('Connexion n8n impossible : ' . $err);
            }
            $raw = (string)$raw;
        } else {
            $httpOpts = [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'timeout'       => 12,
                'ignore_errors' => true,
            ];
            if (!$isGet) {
                $httpOpts['content'] = $body;
            }
            $ctx = stream_context_create(['http' => $httpOpts]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                throw new RuntimeException('Connexion n8n impossible.');
            }
            $status = 0;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
            $raw = (string)$raw;
        }

        $json = json_decode($raw, true);
        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }
}

if (!function_exists('notif_truthy')) {
    /** true/false depuis une valeur n8n hétérogène (bool, 0/1, "true", "oui"). */
    function notif_truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'oui', 'on'], true);
    }
}

if (!function_exists('notif_extract_rows')) {
    /** Extrait la liste de lignes depuis une réponse n8n tolérante au format. */
    function notif_extract_rows($json): array
    {
        $unwrap = static function ($v) {
            return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
        };

        if (is_array($json)) {
            foreach (['notifications', 'data', 'results', 'rows', 'items'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    $json = $json[$key];
                    break;
                }
            }
            if ($json === [] || array_key_exists(0, $json)) {
                return array_map($unwrap, array_values($json));
            }
            if (isset($json['json']) && is_array($json['json'])) {
                return [$json['json']];
            }
            if (isset($json['id']) || isset($json['title'])) {
                return [$json];
            }
        }
        return [];
    }
}

if (!function_exists('notif_normalize')) {
    /**
     * Normalise une ligne n8n hétérogène vers une forme stable pour le front :
     *   { id, type, title, message, link, is_read(bool), created_at }
     * Tolère plusieurs noms de colonnes (is_read/read/seen/lu, createdAt/created_at...).
     */
    function notif_normalize(array $row): array
    {
        $pick = static function (array $r, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (isset($r[$k]) && $r[$k] !== '') {
                    return $r[$k];
                }
            }
            return $default;
        };

        $readAt = (string)$pick($row, ['read_at', 'readAt', 'lu_le'], '');
        $isRead = notif_truthy($pick($row, ['is_read', 'read', 'seen', 'lu'], '0')) || ($readAt !== '');

        return [
            'id'         => (string)$pick($row, ['id', '_id', 'uuid'], ''),
            'type'       => (string)$pick($row, ['type', 'category', 'categorie'], 'info'),
            'title'      => (string)$pick($row, ['title', 'titre', 'subject', 'objet'], ''),
            'message'    => (string)$pick($row, ['message', 'body', 'text', 'contenu'], ''),
            'link'       => (string)$pick($row, ['link', 'url', 'href', 'lien'], ''),
            'is_read'    => $isRead,
            'created_at' => (string)$pick($row, ['created_at', 'createdAt', 'date', 'created'], ''),
        ];
    }
}

if (!function_exists('notif_list')) {
    /**
     * Liste normalisée + nombre de non-lus pour un client.
     *
     * @return array{status:int, notifications:array<int,array>, unread:int}
     */
    function notif_list(int $clientId, int $limit = 20): array
    {
        $resp = notif_n8n_call([
            'action'    => 'list',
            'client_id' => $clientId,
            'limit'     => $limit,
        ], 'GET');

        $rows = array_map('notif_normalize', notif_extract_rows($resp['json']));

        // unread : priorité au champ racine renvoyé par n8n (compte GLOBAL exact),
        // sinon repli sur le décompte des lignes renvoyées (peut être partiel).
        $unread = null;
        if (is_array($resp['json']) && array_key_exists('unread', $resp['json'])) {
            $unread = (int)$resp['json']['unread'];
        }
        if ($unread === null) {
            $unread = 0;
            foreach ($rows as $r) {
                if (!$r['is_read']) {
                    $unread++;
                }
            }
        }

        return ['status' => $resp['status'], 'notifications' => $rows, 'unread' => $unread];
    }
}

if (!function_exists('notif_mark_read')) {
    /**
     * Marque une notification (ou toutes si $id vide/null) comme lue.
     *
     * @return array{status:int, json:mixed}
     */
    function notif_mark_read(int $clientId, ?string $id): array
    {
        $payload = ['action' => 'read', 'client_id' => $clientId];
        if ($id === null || $id === '') {
            $payload['all'] = 1;
        } else {
            $payload['id'] = $id;
        }
        $resp = notif_n8n_call($payload, 'POST');
        return ['status' => $resp['status'], 'json' => $resp['json']];
    }
}

if (!function_exists('notify')) {
    /**
     * Crée une notification pour un client (usage MANUEL ou AUTOMATIQUE).
     *
     * Exemple manuel :
     *   require_once __DIR__ . '/../include/notifications.php';
     *   notify((int)$_SESSION['user']['id'], 'Bienvenue', 'Votre compte est prêt.');
     *
     * Exemple automatique (dans votre flux « commande créée ») :
     *   notify($clientId, 'Commande #'.$num.' confirmée',
     *          'Votre commande a bien été enregistrée.', '/commandes', 'order');
     *
     * @param int    $clientId  destinataire ($_SESSION['user']['id'])
     * @param string $title     titre court (obligatoire)
     * @param string $message   texte détaillé (optionnel)
     * @param string $link      lien interne/externe (optionnel)
     * @param string $type      info|success|warning|error|order|invoice|subscription|team
     * @return bool  true si n8n a répondu en 2xx
     */
    function notify(int $clientId, string $title, string $message = '', string $link = '', string $type = 'info'): bool
    {
        if ($clientId <= 0 || trim($title) === '') {
            return false;
        }
        try {
            $resp = notif_n8n_call([
                'action'    => 'create',
                'client_id' => $clientId,
                'type'      => $type,
                'title'     => $title,
                'message'   => $message,
                'link'      => $link,
            ], 'POST');
        } catch (\Throwable $e) {
            return false;
        }
        return $resp['status'] >= 200 && $resp['status'] < 300;
    }
}
