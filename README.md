# Portail Gestion — GNL Solution

Portail du personnel GNL : clients, services, support, boutique et documentation.
Interface alignée sur la maquette « Portail Gestion » (même charte que l'Espace Client).

Toutes les données passent par `data/portail_api.php`, qui relaie vers le webhook n8n
`https://n8n.gnl-cloud.us/webhook/portail-gestion-infrastructure` (POST JSON, champ `action`).
Les écritures exigent un POST navigateur avec l'en-tête `X-CSRF-Token` (`$_SESSION['csrf']`).

## Pages

| URL | Fichier | Contenu |
|---|---|---|
| `/dashboard` | pages/dashboard.php | KPI (tickets ouverts, entreprises, services en ligne, factures impayées), tickets à traiter, services en alerte, dernières commandes, boutons « Planifier une maintenance » et « Ajouter une entreprise » |
| `/entreprises` | pages/entreprises.php | Organisations Keycloak, invitation de membres (`org.invite_member`). `?add=1` ouvre la modale d'ajout |
| `/services` | pages/services.php | **Nouveau** — tous les services clients par catégorie + onglet Services GNL |
| `/services-gnl` | pages/services-gnl.php | **Nouveau** — déploiements internes (webintern) : état, pods, renommer, redémarrer, briques critiques |
| `/serveurs-dns` | pages/serveurs-dns.php | **Nouveau** — serveurs DNS + zones hébergées |
| `/facture` `/abonnements` `/commande` | pages/… | Facturation clients (onglets) |
| `/boutique` | pages/boutique.php | **Nouveau** — articles, visibilité, stock, options |
| `/equipes` | pages/equipes.php | Équipe GNL (lecture seule, Keycloak) |
| `/gestion-ticket` | pages/gestion-ticket.php | Console support |
| `/documentation` | pages/documentation.php | **Refait** — rédaction des articles de l'Espace Client (brouillon / publication, aperçu client) |

Le routage générique de `.htaccess` (`/x` → `pages/x.php`) couvre les nouvelles pages.

### Fichiers communs ajoutés

- `include/staff_shell.php` — coque des nouvelles pages (session, `<head>`, header, barre latérale).
- `include/maintenance_modal.php` — modale « Planifier une maintenance » (présente sur toutes les nouvelles pages, ouverte par `[data-gs-open="maintModal"]`).
- `assets/js/gnl-staff.js` — `GS.get` / `GS.post`, modales, toasts, envoi de la maintenance.
- `assets/js/gestion-dashboard.js` — tableau de bord.
- `assets/styles/gnl-gestion.css` — composants propres au personnel (préfixe `gs-`).

## Actions n8n à créer

Chaque payload contient aussi `client_id` (id de l'agent connecté). Réponse attendue : JSON,
HTTP 2xx ; `{ "ok": false, "error": "…" }` ou un code HTTP hors 2xx signale un échec.

### Boutique

`product.list` existe déjà (catalogue lu par `include/services_catalog.php`). Pour la page
Boutique, chaque ligne doit en plus exposer :

```json
{
  "id": "labo", "name": "Le Laboratoire", "subtitle": "VM de formation réseau",
  "slug": "le-laboratoire", "category": "Le Laboratoire",
  "price": 15, "billing": "mensuel | annuel | unique", "description": "…",
  "store_showable": true,
  "stock_limited": true, "stock": 3, "stock_alert": 5,
  "options": [
    { "id": "o3", "name": "Accès SFTP", "slug": "acces-sftp", "price": 0,
      "billing": "mensuel | unique | inclus", "description": "…", "default_checked": true }
  ]
}
```

(Noms tolérés : `prix`, `facturation`, `visible`, `stock_limite`, `stock_alerte`, `product_options`…)

| Action | Payload | Effet |
|---|---|---|
| `product.save` | `id` (vide = création), `name`, `subtitle`, `slug`, `category`, `price`, `billing`, `description`, `store_showable`, `stock_limited`, `stock` (null si illimité), `stock_alert`, `options[]` | Crée / modifie l'article. Pour une création, `options[]` contient toutes les options à créer. Réponse optionnelle `{ product: {…} }` |
| `product.visibility` | `id`, `store_showable` (bool) | Affiche / masque en boutique (l'article reste actif pour les clients existants) |
| `product.stock` | `id`, `stock_limited`, `stock`, `stock_alert` | Envoyé quand seul le stock a changé. À 0 : « Complet » |
| `product.option.add` | `product_id`, `name`, `slug`, `price`, `billing`, `description`, `default_checked` | Ajoute une option à un article existant. Réponse optionnelle `{ option: { id } }` |
| `product.option.delete` | `product_id`, `option_id` | Retire une option |

### Documentation (alimente `/documentation` de l'Espace Client)

| Action | Payload | Effet |
|---|---|---|
| `documentation.list` | `include_drafts: 1` | Déjà existante ; doit maintenant renvoyer aussi `status` (`draft` / `published`), `read_time`, `author`. **Côté Espace Client, ne renvoyer que `status = published`.** |
| `documentation.save` | `id` (null = création), `title`, `category`, `summary`, `content` (HTML nettoyé), `read_time`, `status`, `author` | Crée / met à jour. Réponse optionnelle `{ article: { id, … } }` |
| `documentation.publish` | `id`, `status` | Publie / repasse en brouillon |
| `documentation.delete` | `id` | Supprime |

HTML conservé dans `content` : `h2 h3 p br strong b em i ul ol li code pre a[href] img[src,alt] blockquote`
(nettoyage serveur `gs_clean_html()` ; liens limités à `https://`, `/`, `#`, `mailto:`).

### Maintenances

| Action | Payload | Effet |
|---|---|---|
| `maintenance.create` | `title`, `type` (`planifiee` / `urgente`), `services[]` (noms de deployments ; vide = toute la plateforme), `starts_at`, `ends_at` (ISO 8601), `impact` (`none` / `restarts` / `outage`), `message`, `notify` (bool), `created_by` | Enregistre la maintenance ; si `notify`, prévient les clients concernés (e-mail + notification) et affiche le bandeau dans leur Espace Client |
| `maintenance.list` | — | `{ maintenances: [...] }` (disponible pour un futur affichage) |

## Sources hors n8n

- `/services` : `data/services_menu_api.php` (catalogue des produits achetés) + `data/k8s_api.php?action=list_deployments`.
- `/services-gnl` : `data/k8s_api.php` (`list_deployments`, `list_pods_for_deployment`, `restart_deployment`) + `deployment.list` / `deployment.rename` pour les noms d'affichage.
- `/serveurs-dns` : `dns_server.list` / `dns_server.add` (modale de la barre latérale) / `dns_server.delete`, `domain.list`.

## Hôte n8n

Toutes les URL `api.gnl-solution.fr` ont été remplacées par `n8n.gnl-cloud.us`.
