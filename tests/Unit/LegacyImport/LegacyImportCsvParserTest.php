<?php
declare(strict_types=1);
namespace Tests\Unit\LegacyImport;

use App\Application\LegacyImport\{LegacyImportCsvParser, LegacyImportOptions};
use PHPUnit\Framework\TestCase;

final class LegacyImportCsvParserTest extends TestCase
{
    public function testParsesBomQuotedMultilineHungarianAndMapsDefaults(): void
    {
        $csv = "\xEF\xBB\xBF" . $this->header() . "\r\n" . $this->row('215','accepted','A Bata - naptár','Anna Teszt','anna@example.test','2','-','1 év',"Megjegyzés, első sor\nfolytatás") . "\r\n" . $this->row('216','pending','A Bata - naptár','Béla Teszt','bela@example.test','3','0 év','2 év','') . "\r\n" . $this->row('217','trash','Teszt naptár','Régi Teszt','bad-email','','-','-', '-') . "\r\n";
        $preview = (new LegacyImportCsvParser())->parse($csv);
        self::assertSame(3, $preview->total());
        self::assertSame(['accepted' => 1, 'pending' => 1, 'trash' => 1], $preview->sourceStatuses);
        self::assertSame(2, $preview->selected(new LegacyImportOptions()));
        self::assertSame("Megjegyzés, első sor\nfolytatás", $preview->rows[0]->notes);
        self::assertSame([1], $preview->rows[0]->childAges);
        self::assertSame('confirmed', $preview->rows[0]->mappedStatus()?->value);
        self::assertSame('pending', $preview->rows[1]->mappedStatus()?->value);
    }

    public function testDetectsInvalidDateLengthsConflictingChildFieldsAndEmail(): void
    {
        $row = $this->row('218','accepted','A Bata - naptár','Név','not-an-email','2','4 év','5 év','');
        $row = str_replace(['2027-01-12','"2","-","-","4 év","5 év"'], ['2027-01-09','"1","2","-","4 év","5 év"'], $row);
        $preview = (new LegacyImportCsvParser())->parse($this->header()."\n".$row);
        $errors = $preview->rows[0]->errors;
        self::assertContains('departure_not_after_arrival', $errors);
        self::assertContains('invalid_email', $errors);
        self::assertContains('conflicting_child_counts', $errors);
    }

    private function header(): string
    {
        return implode(',', array_map(static fn (string $v): string => '"'.str_replace('"','""',$v).'"', [
            'Booking ID','Booking Status','Calendar ID','Calendar Name','Start Date','End Date','Stay Length - Days','Stay Length - Nights','Date Created','Név (ID:1)','Email (ID:2)','Telefonszám (ID:3)','Vendégek száma (ID:7)','Ebből 18 év alatti (ID:8)','Ebből 18 év alatti (ID:14)','Ebből 18 év alatti (ID:15)','18 év alatti kora 1 (ID:11)','18 év alatti kora 2 (ID:12)','18 év alatti kora 3 (ID:13)','Megjegyzés (ID:6)','Elolvastam és elfogadom az Adatvédelmi irányelveket. (ID:5)','Elolvastam és elfogadom a Foglalási szabályzatot. (ID:16)','Elolvastam és elfogadom a Házirendet. (ID:17)',
        ]));
    }

    private function row(string $id,string $status,string $calendar,string $name,string $email,string $total,string $age1,string $age2,string $note): string
    {
        $childCount = ($age1 !== '-' && $age1 !== '' ? 1 : 0) + ($age2 !== '-' && $age2 !== '' ? 1 : 0);
        $values = [$id,$status,'1',$calendar,'2027-01-10','2027-01-12','3','2','2026-12-01',$name,$email,'+36 30 123 4567',$total,(string)$childCount,'-','-', $age1,$age2,'-',$note,'1','1','1'];
        return implode(',', array_map(static fn (string $v): string => '"'.str_replace('"','""',$v).'"', $values));
    }
}
