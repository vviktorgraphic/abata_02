<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\AdminBookingDetailQuery;
use App\Application\Booking\AdminBookingListQuery;
use JsonException;
use PDO;

final class PdoAdminBookingQueryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, int|string>> */
    public function fetchBookingList(AdminBookingListQuery $query): array
    {
        [$where, $parameters] = $this->filters($query);
        $statement = $this->pdo->prepare(
            'SELECT b.reference, b.guest_name AS contact_name, b.arrival_date, b.departure_date,
                    DATEDIFF(b.departure_date, b.arrival_date) AS nights,
                    b.adults, b.children, b.total_amount, b.currency, b.status, b.created_at
             FROM bookings b' . $where . '
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $query->pageSize, PDO::PARAM_INT);
        $statement->bindValue('offset', $query->getOffset(), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'reference' => (string) $row['reference'],
            'contact_name' => (string) $row['contact_name'],
            'arrival_date' => (string) $row['arrival_date'],
            'departure_date' => (string) $row['departure_date'],
            'nights' => (int) $row['nights'],
            'adults' => (int) $row['adults'],
            'children' => (int) $row['children'],
            'party_size' => (int) $row['adults'] + (int) $row['children'],
            'total_amount' => (string) $row['total_amount'],
            'currency' => (string) $row['currency'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    public function countBookings(AdminBookingListQuery $query): int
    {
        [$where, $parameters] = $this->filters($query);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM bookings b' . $where);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function fetchBookingDetail(AdminBookingDetailQuery $query): ?array
    {
        $identifier = trim($query->referenceOrId);
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.reference, b.status, b.guest_name AS contact_name,
                    b.guest_email AS email, b.guest_phone AS phone, b.arrival_date,
                    b.departure_date, DATEDIFF(b.departure_date, b.arrival_date) AS nights,
                    b.adults, b.children, b.notes, b.total_amount, b.currency,
                    b.created_at, b.updated_at
             FROM bookings b
             WHERE b.reference = :reference OR b.id = :id
             LIMIT 1'
        );
        $statement->execute([
            'reference' => $identifier,
            'id' => ctype_digit($identifier) ? (int) $identifier : 0,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $bookingId = (int) $row['id'];
        $history = $this->history($bookingId);
        $emails = $this->emails($bookingId);
        $snapshot = $this->snapshot($bookingId);

        return [
            'id' => $bookingId,
            'reference' => (string) $row['reference'],
            'status' => (string) $row['status'],
            'contact_name' => (string) $row['contact_name'],
            'email' => (string) $row['email'],
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
            'arrival_date' => (string) $row['arrival_date'],
            'departure_date' => (string) $row['departure_date'],
            'nights' => (int) $row['nights'],
            'adults' => (int) $row['adults'],
            'children' => (int) $row['children'],
            'children_ages' => $this->childAges($bookingId),
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'privacy_accepted_at' => null,
            'total_amount' => (string) $row['total_amount'],
            'currency' => (string) $row['currency'],
            'pricing_snapshot' => $snapshot,
            'status_history' => $history,
            'email_outbox' => $emails,
            'pricingSnapshot' => $snapshot,
            'statusHistory' => $history,
            'emailOutbox' => $emails,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array{string, array<string, string>} */
    private function filters(AdminBookingListQuery $query): array
    {
        $conditions = [];
        $parameters = [];
        foreach ([
            ['status', $query->status, 'b.status = :status'],
            ['arrival_from', $query->arrivalFrom?->format('Y-m-d'), 'b.arrival_date >= :arrival_from'],
            ['arrival_until', $query->arrivalUntil?->format('Y-m-d'), 'b.arrival_date <= :arrival_until'],
            ['created_from', $query->createdFrom?->format('Y-m-d H:i:s'), 'b.created_at >= :created_from'],
            ['created_until', $query->createdUntil?->format('Y-m-d H:i:s'), 'b.created_at <= :created_until'],
        ] as [$name, $value, $condition]) {
            if ($value !== null) {
                $conditions[] = $condition;
                $parameters[$name] = $value;
            }
        }
        if ($query->search !== null) {
            $conditions[] = '(b.reference LIKE :search_reference OR b.guest_name LIKE :search_name OR b.guest_email LIKE :search_email OR b.guest_phone LIKE :search_phone)';
            $pattern = '%' . $query->search . '%';
            $parameters['search_reference'] = $pattern;
            $parameters['search_name'] = $pattern;
            $parameters['search_email'] = $pattern;
            $parameters['search_phone'] = $pattern;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    /** @return list<int> */
    private function childAges(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT age FROM booking_child_ages WHERE booking_id = :id ORDER BY position');
        $statement->execute(['id' => $bookingId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, int|string|null>> */
    private function history(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT old_status, new_status AS status, changed_by_admin_id, note AS admin_note, created_at
             FROM booking_status_history WHERE booking_id = :id ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['id' => $bookingId]);

        return array_map(static fn (array $row): array => [
            'old_status' => $row['old_status'] !== null ? (string) $row['old_status'] : null,
            'status' => (string) $row['status'],
            'changed_by_admin_id' => $row['changed_by_admin_id'] !== null ? (int) $row['changed_by_admin_id'] : null,
            'admin_note' => $row['admin_note'] !== null ? (string) $row['admin_note'] : null,
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @return list<array<string, int|string|null>> */
    private function emails(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT message_type AS type, status, attempts, last_error, created_at, updated_at, sent_at
             FROM email_outbox WHERE booking_id = :id ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['id' => $bookingId]);

        return array_map(static fn (array $row): array => [
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'sent_at' => $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
        ], $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    private function snapshot(int $bookingId): ?array
    {
        $statement = $this->pdo->prepare('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id = :id LIMIT 1');
        $statement->execute(['id' => $bookingId]);
        $json = $statement->fetchColumn();
        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \RuntimeException('Stored pricing snapshot is invalid.', 0, $error);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
