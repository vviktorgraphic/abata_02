<?php

declare(strict_types=1);

namespace Tests\Integration\Authentication;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Auth\PdoAdminCredentialRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoAdminBookingNotificationPreferencesTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $adminIds = [];
    /** @var array<int, bool> */
    private array $originalPreferences = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        foreach ($this->pdo->query('SELECT id, receives_booking_notifications FROM admins')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->originalPreferences[(int) $row['id']] = (bool) $row['receives_booking_notifications'];
        }
        $this->pdo->exec('UPDATE admins SET receives_booking_notifications = FALSE');
        $insert = $this->pdo->prepare(
            'INSERT INTO admins (email, password_hash, name, is_active, receives_booking_notifications)
             VALUES (:email, :hash, :name, TRUE, :receives)'
        );
        foreach ([false, true, false] as $position => $receives) {
            $insert->execute([
                'email' => 'bulk-' . bin2hex(random_bytes(6)) . '@example.invalid',
                'hash' => password_hash('integration-only-password', PASSWORD_DEFAULT),
                'name' => 'Bulk Admin ' . $position,
                'receives' => $receives ? 1 : 0,
            ]);
            $this->adminIds[] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->adminIds as $id) {
            $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $id]);
        }
        $restore = $this->pdo->prepare('UPDATE admins SET receives_booking_notifications = :receives WHERE id = :id');
        foreach ($this->originalPreferences as $id => $receives) {
            $restore->execute(['receives' => $receives ? 1 : 0, 'id' => $id]);
        }
    }

    public function test_zero_one_and_multiple_selections_replace_all_preferences_atomically(): void
    {
        $repository = new PdoAdminCredentialRepository($this->pdo);

        self::assertSame(
            [['id'=>$this->adminIds[1],'old'=>true,'new'=>false]],
            $repository->replaceBookingNotificationRecipients([]),
        );
        self::assertSame([], $this->selected());

        self::assertSame(
            [['id'=>$this->adminIds[0],'old'=>false,'new'=>true]],
            $repository->replaceBookingNotificationRecipients([$this->adminIds[0]]),
        );
        self::assertSame([$this->adminIds[0]], $this->selected());

        $changes = $repository->replaceBookingNotificationRecipients([$this->adminIds[1], $this->adminIds[2]]);
        self::assertCount(3, $changes);
        self::assertSame([$this->adminIds[1], $this->adminIds[2]], $this->selected());
        self::assertSame([], $repository->replaceBookingNotificationRecipients([$this->adminIds[1], $this->adminIds[2]]));
    }

    public function test_unknown_selection_is_rejected_without_partial_update(): void
    {
        $repository = new PdoAdminCredentialRepository($this->pdo);
        $before = $this->selected();
        try {
            $repository->replaceBookingNotificationRecipients([$this->adminIds[0], PHP_INT_MAX]);
            self::fail('Unknown admin selection was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('A kijelölt felhasználó nem található.', $error->getMessage());
        }
        self::assertSame($before, $this->selected());
    }

    /** @return list<int> */
    private function selected(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->adminIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM admins WHERE id IN ($placeholders) AND receives_booking_notifications = TRUE ORDER BY id"
        );
        $statement->execute($this->adminIds);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
