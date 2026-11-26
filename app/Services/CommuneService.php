<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class CommuneService
{
    public function page(?string $search, int $page, int $perPage = 50): array
    {
        $pdo = Database::pdo();
        $where = '';
        $args = [];
        if ($search !== null) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where = ' WHERE nom LIKE ? OR district LIKE ? OR region LIKE ?';
            $args = [$like, $like, $like];
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM communes$where");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $stmt = $pdo->prepare("SELECT code, nom, district, region, latitude, longitude
            FROM communes$where ORDER BY nom, id LIMIT ? OFFSET ?");
        foreach ($args as $i => $value) $stmt->bindValue($i + 1, $value);
        $stmt->bindValue(count($args) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($args) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['communes' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function create(array $c): void
    {
        Database::pdo()->prepare('INSERT INTO communes (code, nom, district, region, latitude, longitude)
            VALUES (:code, :nom, :district, :region, :latitude, :longitude)')->execute($c);
    }
}
