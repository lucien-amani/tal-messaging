<?php
declare(strict_types=1);

namespace App\Modules\Site\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;

/** Demandes de contact du site public → prospects du pipeline commercial. Anti-spam : champ piège, limite par IP, validation stricte. */
final class LeadService
{
    public const STATUSES = ['new' => 'Nouveau', 'contacted' => 'Contacté', 'qualified' => 'Qualifié', 'won' => 'Gagné', 'lost' => 'Perdu'];
    public const VOLUMES = ['<10k' => 'Moins de 10 000 SMS / mois', '10k-100k' => '10 000 à 100 000', '100k-1m' => '100 000 à 1 million', '>1m' => 'Plus d’un million'];

    /** @param array<string,mixed> $in */
    public static function create(array $in, string $ip): void
    {
        if (trim((string) ($in['website'] ?? '')) !== '') {
            return; // champ piège rempli : on répond comme si tout allait bien
        }
        $name = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $message = trim((string) ($in['message'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = ['Indiquez votre nom (2 à 120 caractères).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = ['Adresse e-mail invalide.'];
        }
        if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
            $errors['message'] = ['Décrivez votre besoin (10 à 2 000 caractères).'];
        }
        $country = strtoupper(trim((string) ($in['country'] ?? '')));
        if ($country !== '' && !isset(Config::get('countries.countries')[$country])) {
            $errors['country'] = ['Pays inconnu.'];
        }
        $vol = (string) ($in['monthly_volume'] ?? '');
        if ($vol !== '' && !isset(self::VOLUMES[$vol])) {
            $errors['monthly_volume'] = ['Volume invalide.'];
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        if ((int) Database::scalar('SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at >= ?', [$ip, gmdate('Y-m-d H:i:s', time() - 3600)]) >= 5) {
            throw ApiException::tooMany('Trop de demandes depuis votre connexion. Réessayez dans une heure.');
        }
        Database::execute('INSERT INTO leads (uuid, name, company, email, phone, country, monthly_volume, message, ip) VALUES (?,?,?,?,?,?,?,?,?)', [
            uuid4(), $name, self::opt($in['company'] ?? null, 150), $email, self::opt($in['phone'] ?? null, 32), $country ?: null, $vol ?: null, $message, substr($ip, 0, 45),
        ]);
    }

    private static function opt(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** @return array<int,array<string,mixed>> */
    public static function list(?string $status): array
    {
        $rows = Database::select(
            "SELECT l.id, l.name, l.company, l.email, l.phone, l.country, l.monthly_volume, l.message, l.status, l.notes, l.created_at, CONCAT(u.first_name, ' ', u.last_name) AS owner
             FROM leads l LEFT JOIN users u ON u.id = l.owner_id" . ($status && isset(self::STATUSES[$status]) ? ' WHERE l.status = ?' : '') . ' ORDER BY l.id DESC LIMIT 200',
            $status && isset(self::STATUSES[$status]) ? [$status] : []
        );
        return $rows;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function update(int $id, array $in, int $userId): array
    {
        $lead = Database::first('SELECT id FROM leads WHERE id = ?', [$id]) ?? throw ApiException::notFound('Prospect introuvable.');
        $sets = [];
        $p = [];
        if (isset($in['status'])) {
            if (!isset(self::STATUSES[(string) $in['status']])) {
                throw ApiException::validation(['status' => ['Statut invalide.']]);
            }
            $sets[] = 'status = ?';
            $p[] = (string) $in['status'];
            $sets[] = 'owner_id = COALESCE(owner_id, ?)';
            $p[] = $userId;
        }
        if (array_key_exists('notes', $in)) {
            $sets[] = 'notes = ?';
            $p[] = mb_substr(trim((string) $in['notes']), 0, 5000) ?: null;
        }
        if ($sets) {
            $p[] = $lead['id'];
            Database::execute('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);
        }
        return (array) Database::first("SELECT l.id, l.name, l.company, l.email, l.phone, l.country, l.monthly_volume, l.message, l.status, l.notes, l.created_at, CONCAT(u.first_name, ' ', u.last_name) AS owner FROM leads l LEFT JOIN users u ON u.id = l.owner_id WHERE l.id = ?", [$id]);
    }
}
