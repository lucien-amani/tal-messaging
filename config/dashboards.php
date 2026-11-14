<?php
declare(strict_types=1);

/**
 * Les 7 espaces. Chaque dashboard exige la permission access_dashboard_<slug>.
 * `links` : raccourcis vers les pages du système (filtrés par permission) — c'est ce qui interconnecte les écrans.
 */
return [
    'ceo' => ['label' => 'CEO', 'icon' => 'chart', 'description' => 'Cockpit de direction : revenus, clients, livraison, alertes.', 'links' => [
        ['label' => 'Wallet & recharges', 'path' => '/billing', 'perm' => 'view_wallet', 'icon' => 'wallet', 'desc' => 'Soldes, paiements à confirmer, factures'],
        ['label' => 'Tarifs', 'path' => '/billing/pricing', 'perm' => 'manage_pricing', 'icon' => 'currency-dollar', 'desc' => 'Prix par pays, opérateur et client'],
        ['label' => 'Clients', 'path' => '/clients', 'perm' => 'view_all_tenants', 'icon' => 'building', 'desc' => 'Organisations, statut, fiche client'],
        ['label' => 'Pipeline commercial', 'path' => '/pipeline', 'perm' => 'manage_crm', 'icon' => 'briefcase', 'desc' => 'Prospects du site public'],
        ['label' => 'Fournisseurs', 'path' => '/providers', 'perm' => 'manage_providers', 'icon' => 'plug-connected', 'desc' => 'API d’envoi, router, simulation'],
        ['label' => 'Sender IDs', 'path' => '/sender-ids', 'perm' => 'review_sender_ids', 'icon' => 'id-badge-2', 'desc' => 'Validation des expéditeurs'],
        ['label' => 'Équipe interne', 'path' => '/team', 'perm' => 'manage_team', 'icon' => 'users', 'desc' => 'Comptes et rôles'],
        ['label' => 'Tickets support', 'path' => '/tickets', 'perm' => 'manage_tickets', 'icon' => 'headset', 'desc' => 'Demandes des clients'],
        ['label' => 'Apparence', 'path' => '/appearance', 'perm' => 'manage_appearance', 'icon' => 'palette', 'desc' => 'Thème de la plateforme'],
    ]],
    'cto' => ['label' => 'CTO', 'icon' => 'server', 'description' => 'Control Room : santé système, fournisseurs, API, erreurs.', 'links' => [
        ['label' => 'Fournisseurs & router', 'path' => '/providers', 'perm' => 'manage_providers', 'icon' => 'plug-connected', 'desc' => 'Clés, priorités, test de connexion, webhooks'],
        ['label' => 'Clés API', 'path' => '/developer', 'perm' => 'manage_api_keys', 'icon' => 'code', 'desc' => 'Toutes les clés des clients'],
        ['label' => 'Message Center', 'path' => '/messages', 'perm' => 'view_messages', 'icon' => 'messages', 'desc' => 'Suivi de tous les messages'],
        ['label' => 'Équipe', 'path' => '/team', 'perm' => 'manage_team', 'icon' => 'users', 'desc' => 'Utilisateurs et rôles'],
        ['label' => 'Clients', 'path' => '/clients', 'perm' => 'view_all_tenants', 'icon' => 'building', 'desc' => 'Organisations'],
    ]],
    'operations' => ['label' => 'Operations', 'icon' => 'send', 'description' => 'Exécution quotidienne des campagnes et des communications.', 'links' => [
        ['label' => 'Message Center', 'path' => '/messages', 'perm' => 'view_messages', 'icon' => 'messages', 'desc' => 'Tous les messages et leur statut'],
        ['label' => 'Campagnes', 'path' => '/campaigns', 'perm' => 'view_campaigns', 'icon' => 'speakerphone', 'desc' => 'Suivi et rapports'],
        ['label' => 'Quick Send', 'path' => '/messages/quick-send', 'perm' => 'send_messages', 'icon' => 'send', 'desc' => 'Envoi rapide pour un client'],
        ['label' => 'Sender IDs', 'path' => '/sender-ids', 'perm' => 'review_sender_ids', 'icon' => 'id-badge-2', 'desc' => 'Valider ou rejeter'],
        ['label' => 'Contacts', 'path' => '/contacts', 'perm' => 'view_contacts', 'icon' => 'address-book', 'desc' => 'Contacts, groupes, segments'],
        ['label' => 'Clients', 'path' => '/clients', 'perm' => 'view_all_tenants', 'icon' => 'building', 'desc' => 'Fiches clients'],
    ]],
    'sales' => ['label' => 'Sales & CS', 'icon' => 'briefcase', 'description' => 'Pipeline commercial et succès client.', 'links' => [
        ['label' => 'Pipeline', 'path' => '/pipeline', 'perm' => 'manage_crm', 'icon' => 'briefcase', 'desc' => 'Prospects du site public'],
        ['label' => 'Clients', 'path' => '/clients', 'perm' => 'view_all_tenants', 'icon' => 'building', 'desc' => 'Création et suivi des organisations'],
        ['label' => 'Recharges', 'path' => '/billing', 'perm' => 'approve_payments', 'icon' => 'wallet', 'desc' => 'Confirmer les paiements'],
        ['label' => 'Équipe clients', 'path' => '/team', 'perm' => 'manage_team', 'icon' => 'users', 'desc' => 'Utilisateurs des organisations'],
        ['label' => 'Sender IDs', 'path' => '/sender-ids', 'perm' => 'view_sender_ids', 'icon' => 'id-badge-2', 'desc' => 'Consultation'],
    ]],
    'data' => ['label' => 'Data & Compliance', 'icon' => 'database', 'description' => 'Qualité des données, imports et rapports opérateurs.', 'links' => []],
    'support' => ['label' => 'Support', 'icon' => 'headset', 'description' => 'Tickets et assistance aux clients.', 'links' => [
        ['label' => 'Tickets', 'path' => '/tickets', 'perm' => 'manage_tickets', 'icon' => 'headset', 'desc' => 'Traiter, assigner, rechercher un message'],
        ['label' => 'Message Center', 'path' => '/messages', 'perm' => 'view_messages', 'icon' => 'messages', 'desc' => 'Retrouver un message'],
        ['label' => 'Campagnes', 'path' => '/campaigns', 'perm' => 'view_campaigns', 'icon' => 'speakerphone', 'desc' => 'Consulter une campagne'],
        ['label' => 'Clients', 'path' => '/clients', 'perm' => 'view_all_tenants', 'icon' => 'building', 'desc' => 'Fiches clients'],
    ]],
    'client' => ['label' => 'Client', 'icon' => 'building', 'description' => 'Espace des entreprises clientes.', 'links' => [
        ['label' => 'Quick Send', 'path' => '/messages/quick-send', 'perm' => 'send_messages', 'icon' => 'send', 'desc' => 'Envoyer un SMS maintenant'],
        ['label' => 'Campagnes', 'path' => '/campaigns', 'perm' => 'view_campaigns', 'icon' => 'speakerphone', 'desc' => 'Créer et suivre vos campagnes'],
        ['label' => 'Contacts', 'path' => '/contacts', 'perm' => 'view_contacts', 'icon' => 'address-book', 'desc' => 'Contacts, groupes, segments'],
        ['label' => 'Wallet', 'path' => '/billing', 'perm' => 'view_wallet', 'icon' => 'wallet', 'desc' => 'Solde, recharge, factures'],
        ['label' => 'Sender IDs', 'path' => '/sender-ids', 'perm' => 'view_sender_ids', 'icon' => 'id-badge-2', 'desc' => 'Vos expéditeurs'],
        ['label' => 'API Developer', 'path' => '/developer', 'perm' => 'manage_api_keys', 'icon' => 'code', 'desc' => 'Clés API et documentation'],
        ['label' => 'Équipe', 'path' => '/team', 'perm' => 'manage_team', 'icon' => 'users', 'desc' => 'Inviter des collaborateurs'],
        ['label' => 'Assistance', 'path' => '/tickets', 'perm' => 'create_tickets', 'icon' => 'headset', 'desc' => 'Ouvrir un ticket'],
    ]],
];
