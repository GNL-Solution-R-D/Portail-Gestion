<?php

/**
 * include/portail_api_client.php
 *
 * Client serveur PARTAGÉ vers le webhook n8n « data-portail ».
 *
 * Centralise la logique de transport historiquement embarquée dans
 * data/portail_api.php (fonction n8n_call) afin qu'elle soit réutilisable
 * AILLEURS que dans le proxy navigateur — en particulier à la connexion
 * (keycloak_callback.php) pour alimenter la table « team ».
 *
 * Principes (identiques à data/portail_api.php) :
 *   - UN SEUL webhook n8n, toujours appelé en POST JSON ;
 *   - le champ "action" est préfixé par le module ("team.ensure", …) ;
 *   - le client_id n'est JAMAIS pris du navigateur : l'appelant le fournit
 *     depuis une source de confiance (session / claims Keycloak).
 *
 * Aucune sortie : ce fichier ne définit que des fonctions (idempotent à inclure).
 */

declare(strict_types=1);

if (!defined('PORTAIL_API_DEFAULT_URL')) {
    // Repli si la variable d'environnement N8N_DATA_PORTAIL_URL est absente.
    define('PORTAIL_API_DEFAULT_URL', 'https://api.gnl-solution.fr/webhook/data-portail');
}

if (!function_exists('portailApiEnvNonEmpty')) {
    /** Variable d'environnement uniquement si définie ET non vide après trim. */
    function portailApiEnvNonEmpty(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}

if (!function_exists('portailApiUrl')) {
    function portailApiUrl(): string
    {
        return portailApiEnvNonEmpty('N8N_DATA_PORTAIL_URL') ?? PORTAIL_API_DEFAULT_URL;
    }
}

if (!function_exists('portailApiCall')) {
    /**
     * Relaie un payload au webhook n8n UNIQUE, toujours en POST JSON,
     * et renvoie la réponse décodée.
     *
     * Comportement et forme de retour STRICTEMENT identiques à l'ancien
     * data/portail_api.php::n8n_call() (compatibilité totale : les défauts
     * 12s / 6s reproduisent l'ancien comportement). Des timeouts plus courts
     * peuvent être passés pour les appels « best-effort » (ex. à la connexion).
     *
     * @return array{status:int, json:mixed, raw:string}
     */
    function portailApiCall(array $payload, int $timeout = 12, int $connectTimeout = 6): array
    {
        $url     = portailApiUrl();
        $token   = portailApiEnvNonEmpty('N8N_WEBHOOK_TOKEN');
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) {
            // n8n « Header Auth » : adapter le nom d'en-tête à votre workflow.
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-GNL-Token: ' . $token;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            ]);
            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                throw new RuntimeException('Connexion n8n impossible : ' . $err);
            }
            $raw = (string) $raw;
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                throw new RuntimeException('Connexion n8n impossible.');
            }
            $status = 0;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
            $raw = (string) $raw;
        }

        $json = json_decode($raw, true);
        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }
}

if (!function_exists('portailFirstNonEmpty')) {
    /** Première valeur non vide parmi plusieurs clés candidates. */
    function portailFirstNonEmpty(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && trim((string) $row[$k]) !== '') {
                return trim((string) $row[$k]);
            }
        }
        return $default;
    }
}

if (!function_exists('portailBuildTeamEnsurePayload')) {
    /**
     * Construit le payload "team.ensure" à partir d'un utilisateur de session
     * (issu de keycloakBuildSessionUser). Tolérant aux variantes de clés.
     *
     * Le client_id provient de la session (non falsifiable). n8n se charge du
     * find-or-create de l'équipe (regroupée par siret/structure/namespace) et
     * de l'upsert de la ligne d'appartenance dans la table « team ».
     */
    function portailBuildTeamEnsurePayload(array $sessionUser, string $source = 'keycloak_callback'): array
    {
        $clientId = (int) ($sessionUser['id'] ?? 0);

        $permRaw = $sessionUser['perm_id'] ?? $sessionUser['permission'] ?? $sessionUser['role_id'] ?? null;
        $permId  = (is_numeric($permRaw)) ? (int) $permRaw : null;

        // Clés alignées sur keycloakBuildSessionUser() (include/keycloak_auth.php).
        // Les fallbacks restent tolérants si la forme de session évolue.
        return [
            'action'         => 'team.ensure',
            'client_id'      => $clientId,

            // ── Identité du membre (colonnes directes de la table « team ») ──
            'civilite'       => portailFirstNonEmpty($sessionUser, ['civilite', 'title']),
            'prenom'         => portailFirstNonEmpty($sessionUser, ['prenom', 'firstName', 'firstname', 'first_name', 'given_name']),
            'nom'            => portailFirstNonEmpty($sessionUser, ['nom', 'lastName', 'lastname', 'last_name', 'family_name']),
            'email'          => portailFirstNonEmpty($sessionUser, ['email', 'ent_email', 'organization_email', 'mail']),
            'telephone'      => portailFirstNonEmpty($sessionUser, ['telephone', 'phone', 'organization_telephone']),
            'fonction'       => portailFirstNonEmpty($sessionUser, ['fonction', 'poste', 'job', 'function', 'job_title']),
            'username'       => portailFirstNonEmpty($sessionUser, ['username', 'preferred_username']),
            'perm_id'        => $permId,

            // ── Identification entreprise (find-or-create de l'équipe côté n8n) ──
            // NB : keycloakBuildSessionUser() expose ces attributs sous des clés
            // « plates » (raison, num_tva, ent_email, cp, comune, voie_*) ET sous
            // des alias « organization_* ». Les fallbacks couvrent les deux formes.
            'siret'          => portailFirstNonEmpty($sessionUser, ['siret', 'organization_siret']),
            'siren'          => portailFirstNonEmpty($sessionUser, ['siren', 'organization_siren']),
            'client_code'    => portailFirstNonEmpty($sessionUser, ['client_code', 'code_client', 'organization_code_client']),

            // Raison sociale : nom canonique « raison » attendu côté n8n + alias
            // « structure » conservé (lu par data/portail_api.php::team.* et l'UI).
            'raison'         => portailFirstNonEmpty($sessionUser, [
                'raison', 'organization_name', 'organization', 'company', 'structure',
            ]),
            'structure'      => portailFirstNonEmpty($sessionUser, [
                'raison', 'organization_name', 'organization', 'nom_commercial', 'company', 'structure',
            ]),
            'nom_commercial' => portailFirstNonEmpty($sessionUser, ['nom_commercial', 'organization_commercial_name']),

            // TVA : nom canonique « tva » + alias historique « num_tva ».
            'tva'            => portailFirstNonEmpty($sessionUser, ['num_tva', 'tva', 'organization_tva']),
            'num_tva'        => portailFirstNonEmpty($sessionUser, ['num_tva', 'tva', 'organization_tva']),

            // Champs « select » entreprise.
            'ent_type'       => portailFirstNonEmpty($sessionUser, ['ent_type', 'organization_type_tiers']),
            'entite_legal'   => portailFirstNonEmpty($sessionUser, ['entite_legal', 'organization_type_entite']),

            // Coordonnées entreprise (étaient ABSENTES du payload → jamais reçues par n8n).
            'ent_email'      => portailFirstNonEmpty($sessionUser, ['ent_email', 'organization_email']),
            'pays'           => portailFirstNonEmpty($sessionUser, ['pays', 'organization_pays']),
            'site_web'       => portailFirstNonEmpty($sessionUser, ['site_web', 'organization_site_web']),

            // Adresse entreprise. Attention : la session stocke la commune sous la
            // clé typo « comune » → indispensable de l'inclure dans les fallbacks.
            'voie_nbr'       => portailFirstNonEmpty($sessionUser, ['voie_nbr', 'organization_voie_nbr']),
            'voie_name'      => portailFirstNonEmpty($sessionUser, ['voie_name', 'organization_voie_name']),
            'cp'             => portailFirstNonEmpty($sessionUser, ['cp', 'organization_cp']),
            'commune'        => portailFirstNonEmpty($sessionUser, ['commune', 'comune', 'organization_commune']),

            // Téléphone entreprise — DISTINCT du « telephone » membre ci-dessus
            // (clé séparée pour ne pas écraser le téléphone du membre dans le payload).
            'telephone_entreprise' => portailFirstNonEmpty($sessionUser, ['organization_telephone', 'telephone', 'phone']),

            // ── Contexte Kubernetes (clé de regroupement éventuelle) ──
            'k8s_namespace'  => portailFirstNonEmpty($sessionUser, ['k8s_namespace', 'namespace']),
            'cluster'        => portailFirstNonEmpty($sessionUser, ['cluster', 'cluster_id']),

            'source'         => $source,
        ];
    }
}

if (!defined('PORTAIL_STATS_TZ')) {
    // Fuseau métier pour l'agrégation des stats. Les compteurs journaliers sont
    // stockés à minuit LOCAL exprimé en UTC (ex. 2026-05-31T22:00:00Z = 1er juin
    // à Paris) : agréger en UTC ferait basculer les hits de fin de mois dans le
    // mauvais mois. On agrège donc dans ce fuseau.
    define('PORTAIL_STATS_TZ', 'Europe/Paris');
}

if (!function_exists('portailStripStatsSuffix')) {
    /**
     * Retire le suffixe « -stats » (ou « _stats ») du nom de service pour
     * retrouver le nom de deployment. Ex. "slapia-web-stats" → "slapia-web".
     */
    function portailStripStatsSuffix(string $service): string
    {
        $s = trim($service);
        foreach (['-stats', '_stats'] as $suffix) {
            if ($s !== $suffix && str_ends_with($s, $suffix)) {
                return trim(substr($s, 0, -strlen($suffix)));
            }
        }
        return $s;
    }
}

if (!function_exists('portailStatMonthKey')) {
    /**
     * Convertit une date hétérogène (ISO 8601 avec « Z », timestamp unix, …) en
     * clé de mois "Y-m" dans le fuseau métier ($tz). Renvoie null si illisible.
     */
    function portailStatMonthKey(string $dateRaw, string $tz = PORTAIL_STATS_TZ): ?string
    {
        $dateRaw = trim($dateRaw);
        if ($dateRaw === '') {
            return null;
        }
        try {
            $dt = is_numeric($dateRaw)
                ? (new DateTimeImmutable('@' . (int) $dateRaw))
                : new DateTimeImmutable($dateRaw);
        } catch (Throwable $e) {
            $ts = strtotime($dateRaw);
            if ($ts === false) {
                return null;
            }
            $dt = new DateTimeImmutable('@' . $ts);
        }
        try {
            return $dt->setTimezone(new DateTimeZone($tz))->format('Y-m');
        } catch (Throwable $e) {
            return $dt->format('Y-m');
        }
    }
}

if (!function_exists('portailAggregateRawStatRows')) {
    /**
     * Agrège des lignes BRUTES { service, date, hit } (forme réelle renvoyée par
     * n8n / table stat_portail) en une map { '<deployment>' => stats } à la forme
     * historique du sidecar :
     *   { current_month_hits, previous_month_hits, by_month: ["Y-m" => int] }
     *
     * - "service" perd son suffixe « -stats » → nom de deployment ;
     * - "date" est ramenée au mois calendaire dans le fuseau métier ($tz) ;
     * - "hit" est sommé par (deployment, mois) ;
     * - current/previous = mois courant / mois précédent (« maintenant » en $tz).
     *
     * Tolérant aux variantes de clés (service/deployment, date/day, hit/hits/count).
     */
    function portailAggregateRawStatRows(array $rows, string $tz = PORTAIL_STATS_TZ): array
    {
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($tz));
        } catch (Throwable $e) {
            $now = new DateTimeImmutable('now');
        }
        $curKey  = $now->format('Y-m');
        $prevKey = $now->modify('first day of previous month')->format('Y-m');

        $acc = []; // deployment => [ "Y-m" => hits ]
        foreach ($rows as $row) {
            if (isset($row['json']) && is_array($row['json'])) {
                $row = $row['json'];
            }
            if (!is_array($row)) {
                continue;
            }

            $service = trim((string) (
                $row['service'] ?? $row['deployment_name'] ?? $row['deployment'] ?? $row['name'] ?? ''
            ));
            if ($service === '') {
                continue;
            }
            $dep = portailStripStatsSuffix($service);
            if ($dep === '') {
                continue;
            }

            $monthKey = portailStatMonthKey(
                (string) ($row['date'] ?? $row['day'] ?? $row['date_stat'] ?? $row['createdAt'] ?? ''),
                $tz
            );
            if ($monthKey === null) {
                continue;
            }

            $hit = (int) ($row['hit'] ?? $row['hits'] ?? $row['count'] ?? 0);

            if (!isset($acc[$dep])) {
                $acc[$dep] = [];
            }
            $acc[$dep][$monthKey] = ($acc[$dep][$monthKey] ?? 0) + $hit;
        }

        $out = [];
        foreach ($acc as $dep => $byMonth) {
            ksort($byMonth);
            $out[$dep] = [
                'current_month_hits'  => (int) ($byMonth[$curKey]  ?? 0),
                'previous_month_hits' => (int) ($byMonth[$prevKey] ?? 0),
                'by_month'            => $byMonth,
            ];
        }
        return $out;
    }
}

if (!function_exists('portailNormalizeDeploymentStats')) {
    /**
     * Normalise une entrée de stats deployment vers la forme HISTORIQUE du
     * sidecar attendue par le dashboard :
     *   { current_month_hits:int, previous_month_hits:int, by_month: array<string,int> }
     *
     * Tolérant aux variantes de clés (snake_case / camelCase) afin de ne pas
     * dépendre du nommage exact côté n8n.
     */
    function portailNormalizeDeploymentStats($entry): array
    {
        $entry = is_array($entry) ? $entry : [];

        // Déballe l'item n8n { "json": {...} } si présent.
        if (isset($entry['json']) && is_array($entry['json'])) {
            $entry = $entry['json'];
        }

        $byMonth    = [];
        $rawByMonth = $entry['by_month'] ?? $entry['byMonth'] ?? $entry['months'] ?? [];
        if (is_array($rawByMonth)) {
            foreach ($rawByMonth as $month => $count) {
                $key = trim((string) $month);
                if ($key !== '') {
                    $byMonth[$key] = (int) $count;
                }
            }
        }

        return [
            'current_month_hits'  => (int) ($entry['current_month_hits']  ?? $entry['currentMonthHits']  ?? $entry['current']  ?? 0),
            'previous_month_hits' => (int) ($entry['previous_month_hits'] ?? $entry['previousMonthHits'] ?? $entry['previous'] ?? 0),
            'by_month'            => $byMonth,
        ];
    }
}

if (!function_exists('portailExtractStatsByDeployment')) {
    /**
     * Extrait une map { '<deployment>' => stats } depuis une réponse n8n
     * tolérante au format. Formes acceptées :
     *
     *   0) FORME RÉELLE — lignes BRUTES { service, date, hit } (table
     *      stat_portail) : agrégées par mois (fuseau métier) via
     *      portailAggregateRawStatRows().
     *   1) { "by_deployment" : { "<deployment>": {current_month_hits,...}, ... } }
     *      (alias acceptés : "stats_by_deployment", "stats") — déjà agrégé.
     *   2) { "deployments" : [ {deployment_name, current_month_hits, by_month, ...}, ... ] }
     *      (alias conteneur : "data" / "rows" / "items" / "results" ; item n8n
     *       { "json": {...} } toléré) — déjà agrégé.
     *   3) tableau brut de lignes [ {deployment_name, ...}, ... ] — déjà agrégé.
     *
     * Détection « liste » alignée sur data/portail_api.php::extract_rows()
     * (clé numérique 0 présente, ou tableau vide) pour rester compatible.
     */
    function portailExtractStatsByDeployment($json, string $tz = PORTAIL_STATS_TZ): array
    {
        if (!is_array($json)) {
            return [];
        }

        $isList = static fn (array $a): bool => $a === [] || array_key_exists(0, $a);

        // (1) Map directe deployment ⇒ stats (objet associatif, déjà agrégé).
        foreach (['by_deployment', 'stats_by_deployment', 'stats'] as $key) {
            if (isset($json[$key]) && is_array($json[$key]) && !$isList($json[$key])) {
                $out = [];
                foreach ($json[$key] as $dep => $entry) {
                    $dep = trim((string) $dep);
                    if ($dep !== '') {
                        $out[$dep] = portailNormalizeDeploymentStats($entry);
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        // Extraction de la liste de lignes (conteneur toléré).
        $rows = $json;
        foreach (['deployments', 'data', 'results', 'rows', 'items', 'stats'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $rows = $json[$key];
                break;
            }
        }
        if (!is_array($rows) || !$isList($rows)) {
            return [];
        }

        // (0) Lignes BRUTES ? On inspecte la première ligne exploitable :
        //   - présence d'un champ "hit"/"hits"/"count" ET d'une "date"/"service",
        //   - ABSENCE d'un champ déjà agrégé ("by_month"/"current_month_hits").
        $looksRaw = false;
        foreach ($rows as $probe) {
            if (isset($probe['json']) && is_array($probe['json'])) {
                $probe = $probe['json'];
            }
            if (!is_array($probe)) {
                continue;
            }
            $hasAgg = array_key_exists('by_month', $probe)
                || array_key_exists('current_month_hits', $probe)
                || array_key_exists('currentMonthHits', $probe);
            if ($hasAgg) {
                $looksRaw = false;
                break;
            }
            $hasHit  = array_key_exists('hit', $probe) || array_key_exists('hits', $probe) || array_key_exists('count', $probe);
            $hasDim  = array_key_exists('date', $probe) || array_key_exists('service', $probe) || array_key_exists('day', $probe);
            if ($hasHit && $hasDim) {
                $looksRaw = true;
                break;
            }
        }
        if ($looksRaw) {
            return portailAggregateRawStatRows($rows, $tz);
        }

        // (2)/(3) Lignes DÉJÀ agrégées, identifiées par un champ "deployment_name".
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['json']) && is_array($row['json'])) {
                $row = $row['json'];
            }
            if (!is_array($row)) {
                continue;
            }
            $dep = trim((string) ($row['deployment_name'] ?? $row['deployment'] ?? $row['name'] ?? ''));
            if ($dep !== '') {
                $out[$dep] = portailNormalizeDeploymentStats($row);
            }
        }
        return $out;
    }
}

if (!function_exists('portailFetchDashboardStats')) {
    /**
     * Récupère, via le pipeline n8n data-portail (action "stats.dashboard"),
     * les statistiques de requêtes/visites PAR deployment du client courant.
     *
     * Remplace l'ancien fetch DIRECT des sidecars
     * (<deployment>-stats.<namespace>.svc.cluster.local:9090/stats) par un appel
     * centralisé à l'API — cohérent avec le reste du portail (UN SEUL webhook,
     * client_id injecté serveur et non falsifiable).
     *
     * n8n renvoie les lignes BRUTES de la table stat_portail
     * ({ service:"<deployment>-stats", date:"<ISO8601>", hit:int }). La mise en
     * forme (suffixe « -stats » retiré, agrégation par mois en fuseau métier,
     * calcul mois courant / précédent) est faite ICI, côté consommateur.
     *
     * Retour normalisé, identique à la forme HISTORIQUE du sidecar afin que le
     * code aval du dashboard (cartes + graphique) reste INCHANGÉ :
     *   [
     *     'by_deployment' => [
     *        '<deployment>' => ['current_month_hits'=>int,'previous_month_hits'=>int,'by_month'=>[...]],
     *        ...
     *     ],
     *     'status' => int,   // code HTTP n8n (diagnostic / badge d'erreur)
     *   ]
     *
     * Best-effort : ne lève jamais d'exception bloquante. En cas de client_id
     * absent ou de réponse vide, renvoie une map vide (le dashboard décidera
     * d'un éventuel repli).
     *
     * @param array    $sessionUser     utilisateur de session (keycloakBuildSessionUser)
     * @param string[] $deploymentNames liste optionnelle des deployments connus (hint n8n)
     * @return array{by_deployment: array<string,array>, status:int}
     */
    function portailFetchDashboardStats(
        array $sessionUser,
        array $deploymentNames = [],
        int $timeout = 4,
        int $connectTimeout = 2
    ): array {
        $clientId = (int) ($sessionUser['id'] ?? 0);
        if ($clientId <= 0) {
            return ['by_deployment' => [], 'status' => 0];
        }

        $deployments = array_values(array_filter(
            array_map(static fn ($n): string => trim((string) $n), $deploymentNames),
            static fn (string $n): bool => $n !== ''
        ));

        $payload = [
            'action'        => 'stats.dashboard',
            'client_id'     => $clientId,
            'k8s_namespace' => portailFirstNonEmpty($sessionUser, ['k8s_namespace', 'namespace']),
            'cluster'       => portailFirstNonEmpty($sessionUser, ['cluster', 'cluster_id']),
            'deployments'   => $deployments,
            'source'        => 'dashboard',
        ];

        $resp = portailApiCall($payload, $timeout, $connectTimeout);

        $tz = portailFirstNonEmpty($sessionUser, ['timezone', 'time_zone'], PORTAIL_STATS_TZ);

        return [
            'by_deployment' => portailExtractStatsByDeployment($resp['json'] ?? null, $tz),
            'status'        => (int) ($resp['status'] ?? 0),
        ];
    }
}

if (!function_exists('portailEnsureTeamMembership')) {
    /**
     * Alimente (idempotent) la table « team » pour l'utilisateur fourni,
     * via le pipeline n8n data-portail (action "team.ensure").
     *
     * @return array{status:int, json:mixed, raw:string}
     * @throws RuntimeException si client_id absent ou si n8n est injoignable
     */
    function portailEnsureTeamMembership(
        array $sessionUser,
        string $source = 'keycloak_callback',
        int $timeout = 4,
        int $connectTimeout = 2
    ): array {
        $payload = portailBuildTeamEnsurePayload($sessionUser, $source);
        if ((int) $payload['client_id'] <= 0) {
            throw new RuntimeException('client_id introuvable : impossible d\'alimenter la table team.');
        }
        return portailApiCall($payload, $timeout, $connectTimeout);
    }
}