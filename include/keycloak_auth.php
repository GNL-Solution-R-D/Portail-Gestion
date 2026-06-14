<?php

declare(strict_types=1);

function keycloakGetIssuer(): string
{
    $issuer = trim((string) config('KEYCLOAK_ISSUER', 'https://auth.gnl-solution.fr/auth/realms/client-auth'));
    return rtrim($issuer, '/');
}

function keycloakGetClientId(): string
{
    return trim((string) config('KEYCLOAK_CLIENT_ID', ''));
}

function keycloakGetClientSecret(): string
{
    return trim((string) config('KEYCLOAK_CLIENT_SECRET', ''));
}

function keycloakGetRedirectUri(): string
{
    return trim((string) config('KEYCLOAK_REDIRECT_URI', 'https://gestion.gnl-solution.fr/keycloak_callback.php'));
}

function keycloakGetPostLogoutRedirectUri(): string
{
    return trim((string) config('KEYCLOAK_POST_LOGOUT_REDIRECT_URI', 'https://gestion.gnl-solution.fr/connexion'));
}

function keycloakBuildAuthorizationUrl(): string
{
    $clientId = keycloakGetClientId();
    if ($clientId === '') {
        throw new RuntimeException('KEYCLOAK_CLIENT_ID manquant.');
    }

    $state = bin2hex(random_bytes(24));
    $nonce = bin2hex(random_bytes(24));

    $_SESSION['keycloak_oauth_state'] = $state;
    $_SESSION['keycloak_oauth_nonce'] = $nonce;

    $params = [
        'client_id' => $clientId,
        'redirect_uri' => keycloakGetRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => $state,
        'nonce' => $nonce,
    ];

    return keycloakGetIssuer() . '/protocol/openid-connect/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function keycloakHttpRequest(string $url, array $options = []): array
{
    $ch = curl_init($url);

    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];

    foreach ($options as $opt => $value) {
        $base[$opt] = $value;
    }

    curl_setopt_array($ch, $base);
    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erreur réseau Keycloak: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $response];
    }

    return ['status' => $status, 'body' => $decoded];
}

function keycloakExchangeCodeForTokens(string $code): array
{
    $clientSecret = keycloakGetClientSecret();
    if ($clientSecret === '') {
        throw new RuntimeException('KEYCLOAK_CLIENT_SECRET manquant.');
    }

    $response = keycloakHttpRequest(
        keycloakGetIssuer() . '/protocol/openid-connect/token',
        [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => keycloakGetRedirectUri(),
                'client_id' => keycloakGetClientId(),
                'client_secret' => $clientSecret,
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]
    );

    if (($response['status'] ?? 0) !== 200) {
        throw new RuntimeException('Échec récupération token Keycloak.');
    }

    return is_array($response['body']) ? $response['body'] : [];
}

function keycloakDecodeJwtPayload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }

    $payload = strtr($parts[1], '-_', '+/');
    $pad = strlen($payload) % 4;
    if ($pad > 0) {
        $payload .= str_repeat('=', 4 - $pad);
    }

    $json = base64_decode($payload, true);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function keycloakFetchUserInfo(string $accessToken): array
{
    $token = trim($accessToken);
    if ($token === '') {
        return [];
    }

    $response = keycloakHttpRequest(
        keycloakGetIssuer() . '/protocol/openid-connect/userinfo',
        [
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]
    );

    if (($response['status'] ?? 0) !== 200 || !is_array($response['body'])) {
        return [];
    }

    return $response['body'];
}

function keycloakClaimToString($value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }

    if (is_array($value)) {
        foreach ($value as $candidate) {
            if (is_scalar($candidate)) {
                $normalized = trim((string) $candidate);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }
    }

    return '';
}

function keycloakDeepFindClaim($node, string $needle, int $depth = 0): string
{
    if ($depth > 8) {
        return '';
    }

    // Groupe livré comme chaîne JSON (mapper « Claim JSON Type = String »).
    if (is_string($node)) {
        $trimmed = trim($node);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return keycloakDeepFindClaim($decoded, $needle, $depth + 1);
            }
        }
        return ''; // une chaîne simple n'est pas un conteneur de clés
    }

    if (!is_array($node)) {
        return '';
    }

    // 1) Clé présente à ce niveau.
    if (array_key_exists($needle, $node)) {
        $hit = keycloakClaimToString($node[$needle]);
        if ($hit !== '') {
            return $hit;
        }
        // Valeur non scalaire (objet/tableau imbriqué) -> on continue a descendre.
        if (is_array($node[$needle])) {
            $deep = keycloakDeepFindClaim($node[$needle], $needle, $depth + 1);
            if ($deep !== '') {
                return $deep;
            }
        }
    }

    // 2) Descente recursive : alias d'organisation, couche « attributes »,
    //    elements de tableau multivalue, etc.
    foreach ($node as $child) {
        if (is_array($child) || is_string($child)) {
            $hit = keycloakDeepFindClaim($child, $needle, $depth + 1);
            if ($hit !== '') {
                return $hit;
            }
        }
    }

    return '';
}

function keycloakReadClaim(array $claims, array $keys): string
{
    // 1 & 2) Claims plats + notation pointee (retro-compatibilite totale).
    foreach ($keys as $key) {
        if (array_key_exists($key, $claims)) {
            $stringValue = keycloakClaimToString($claims[$key]);
            if ($stringValue !== '') {
                return $stringValue;
            }
            continue;
        }

        // Supporte les claims imbriques via notation pointee (ex: entreprise.siret).
        if (is_string($key) && str_contains($key, '.')) {
            $pathValue = $claims;
            $found = true;
            foreach (explode('.', $key) as $part) {
                if (!is_array($pathValue) || !array_key_exists($part, $pathValue)) {
                    $found = false;
                    break;
                }
                $pathValue = $pathValue[$part];
            }
            if ($found) {
                $stringValue = keycloakClaimToString($pathValue);
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }
    }

    // 3) Recherche PROFONDE cantonnee aux groupes connus. Traverse les objets
    //    JSON imbriques, les chaines JSON, les tableaux multivalues et la couche
    //    « attributes » de la fonctionnalite Organizations de Keycloak. On derive
    //    le « nom nu » de chaque cle (dernier segment apres un point) pour le
    //    retrouver a n'importe quelle profondeur du sous-arbre du groupe.
    $bareNeedles = [];
    foreach ($keys as $key) {
        $parts = explode('.', (string) $key);
        $bareNeedles[(string) end($parts)] = true;
    }

    foreach (['organization', 'entreprise', 'kubernetes', 'user-metadata'] as $claimGroup) {
        if (!array_key_exists($claimGroup, $claims)) {
            continue;
        }
        $node = $claims[$claimGroup];

        // Groupe livre comme chaine JSON -> decodage defensif.
        if (is_string($node)) {
            $t = trim($node);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($t, true);
                if (is_array($decoded)) {
                    $node = $decoded;
                }
            }
        }
        if (!is_array($node)) {
            continue;
        }

        foreach (array_keys($bareNeedles) as $needle) {
            $hit = keycloakDeepFindClaim($node, $needle);
            if ($hit !== '') {
                return $hit;
            }
        }
    }

    return '';
}

function keycloakBuildSessionUser(array $claims): array
{
    $subject = keycloakReadClaim($claims, ['sub', 'username', 'preferred_username', 'email']);
    $subject = $subject !== '' ? $subject : 'anonymous';

    // user_account_sessions.user_id is INT signed in MySQL.
    // Keep generated IDs inside [1, 2147483647] to avoid SQLSTATE[22003].
    $hashHex = substr(sha1($subject), 0, 8);
    $fallbackId = (int) (hexdec($hashHex) % 2147483647);
    if ($fallbackId <= 0) {
        $fallbackId = 1;
    }

    $username = keycloakReadClaim($claims, ['username', 'preferred_username', 'email']) ?: 'utilisateur';
    $firstName = keycloakReadClaim($claims, ['firstName', 'given_name', 'prenom']);
    $lastName = keycloakReadClaim($claims, ['lastName', 'family_name', 'nom']);
    $email = keycloakReadClaim($claims, ['email']);
    $siren = keycloakReadClaim($claims, ['siren', 'organization.siren', 'entreprise.siren']);
    $siret = keycloakReadClaim($claims, ['siret', 'organization.siret', 'entreprise.siret']);
    $companyName = keycloakReadClaim($claims, ['raison', 'organization.raison', 'entreprise.raison']);
    $commercialName = keycloakReadClaim($claims, ['nom_commercial', 'organization.nom_commercial', 'entreprise.nom_commercial']);
    $clientCode = keycloakReadClaim($claims, ['client_code', 'code_client', 'user-metadata.client_code', 'organization.client_code', 'organization.code_client']);
    $cluster = keycloakReadClaim($claims, ['cluster', 'cluster_id', 'clusterId', 'kubernetes.cluster']);
    return [
        'id' => $fallbackId,
        'siret' => $siret,
        'siren' => $siren,
        'username' => $username,
        'civilite' => keycloakReadClaim($claims, ['civilite']),
        'prenom' => $firstName,
        'nom' => $lastName,
        'firstName' => $firstName,
        'lastName' => $lastName,
        'perm_id' => 1,
        'langue_code' => keycloakReadClaim($claims, ['pref_lang', 'locale', 'langue_code']) ?: 'fr',
        'timezone' => 'Europe/Paris',
        'fonction' => keycloakReadClaim($claims, ['fonction']),
        'phone' => keycloakReadClaim($claims, ['phone']),
        'telephone' => keycloakReadClaim($claims, ['phone', 'telephone']),
        'k8s_namespace' => keycloakReadClaim($claims, ['namespace', 'k8s_namespace', 'namespace_k8s', 'kubernetes.namespace']),
        'namespace' => keycloakReadClaim($claims, ['namespace', 'k8s_namespace', 'namespace_k8s', 'kubernetes.namespace', 'organization.namespace']),
        'cluster_id' => $cluster,
        'cluster' => $cluster,
        'email' => $email,
        'client_code' => $clientCode,
        'code_client' => $clientCode,
        // Attributs entreprise Keycloak.
        'raison' => $companyName,
        'nom_commercial' => $commercialName,
        'ent_type' => keycloakReadClaim($claims, ['ent_type', 'organization.ent_type', 'entreprise.ent_type']),
        'num_tva' => keycloakReadClaim($claims, ['num_tva', 'tva', 'organization.num_tva', 'organization.tva', 'entreprise.num_tva']),
        'entite_legal' => keycloakReadClaim($claims, ['entite_legal', 'organization.entite_legal', 'entreprise.entite_legal']),
        'ent_email' => keycloakReadClaim($claims, ['ent_email', 'organization.ent_email', 'entreprise.ent_email']),
        'pays' => keycloakReadClaim($claims, ['pays', 'organization.pays', 'entreprise.pays']),
        'cp' => keycloakReadClaim($claims, ['cp', 'organization.cp', 'entreprise.cp']),
        'comune' => keycloakReadClaim($claims, ['comune', 'commune', 'organization.comune', 'organization.commune', 'entreprise.comune']),
        'voie_name' => keycloakReadClaim($claims, ['voie_name', 'organization.voie_name', 'entreprise.voie_name']),
        'voie_nbr' => keycloakReadClaim($claims, ['voie_nbr', 'organization.voie_nbr', 'entreprise.voie_nbr']),
        // Alias historiques utilisés par certaines pages.
        'organization' => $companyName,
        'organization_name' => $companyName,
        'organization_commercial_name' => $commercialName,
        'organization_siren' => $siren,
        'organization_siret' => $siret,
        'organization_code_client' => $clientCode,
        'organization_type_entite' => keycloakReadClaim($claims, ['entite_legal', 'type_entité', 'type_entite', 'organization.entite_legal', 'organization.type_entité', 'organization.type_entite']),
        'organization_type_tiers' => keycloakReadClaim($claims, ['ent_type', 'type_tiers', 'organization.ent_type', 'organization.type_tiers']),
        'organization_adresse' => trim(implode(' ', array_filter([
            keycloakReadClaim($claims, ['voie_nbr', 'organization.voie_nbr', 'entreprise.voie_nbr']),
            keycloakReadClaim($claims, ['voie_name', 'organization.voie_name', 'entreprise.voie_name']),
            keycloakReadClaim($claims, ['cp', 'organization.cp', 'entreprise.cp']),
            keycloakReadClaim($claims, ['comune', 'commune', 'organization.comune', 'organization.commune', 'entreprise.comune']),
        ], static fn ($value) => trim((string) $value) !== ''))),
        'organization_telephone' => keycloakReadClaim($claims, ['telephone', 'organization.telephone', 'entreprise.telephone']),
        'organization_email' => keycloakReadClaim($claims, ['ent_email', 'organization.ent_email', 'entreprise.ent_email', 'email']),
        'organization_tva' => keycloakReadClaim($claims, ['num_tva', 'tva', 'organization.num_tva', 'organization.tva', 'entreprise.num_tva']),
        'organization_site_web' => keycloakReadClaim($claims, ['site_web', 'organization.site_web', 'entreprise.site_web']),
    ];
}

function keycloakBuildLogoutUrl(?string $idToken): string
{
    $params = [
        'post_logout_redirect_uri' => keycloakGetPostLogoutRedirectUri(),
    ];

    if (is_string($idToken) && trim($idToken) !== '') {
        $params['id_token_hint'] = trim($idToken);
    }

    return keycloakGetIssuer() . '/protocol/openid-connect/logout?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}