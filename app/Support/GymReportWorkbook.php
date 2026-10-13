<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Payment;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the branch report as a real .xlsx workbook: a Summary sheet plus one sheet
 * each for Members, Payments, Attendance and Plans. Every query goes through the
 * models' gym scope, so a workbook only ever holds the signed-in user's branch.
 */
class GymReportWorkbook
{
    private const PESO = '"₱"#,##0.00';

    private const HEADER_FILL = '151515';

    private const HEADER_INK = 'D9B45A';

    public static function filename(?Gym $gym): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', $gym?->name ?? 'FitGym') ?: 'FitGym';

        return trim($name, '_').'_Report_'.Carbon::now()->format('Y-m-d').'.xlsx';
    }

    /** Writes the workbook to a temporary file and returns its path. */
    public static function build(?Gym $gym): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('FitGym')->setTitle(($gym?->name ?? 'FitGym').' Report');

        $members = Member::where('is_archived', 0)->with('membershipPlan')->orderBy('full_name')->get();
        $payments = Payment::where('is_archived', 0)->with(['member', 'membershipPlan'])->orderByDesc('payment_date')->get();
        $attendance = Attendance::where('is_archived', 0)->with('member')->orderByDesc('check_in_time')->get();
        $plans = MembershipPlan::where('is_archived', 0)->orderBy('price')->get();

        self::summarySheet($book->getActiveSheet(), $gym, $members, $payments, $attendance);
        self::membersSheet($book->createSheet(), $members);
        self::paymentsSheet($book->createSheet(), $payments);
        self::attendanceSheet($book->createSheet(), $attendance);
        self::plansSheet($book->createSheet(), $plans, $members, $payments);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'fitgym-report-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private static function summarySheet(Worksheet $sheet, ?Gym $gym, $members, $payments, $attendance): void
    {
        $sheet->setTitle('Summary');
        $now = Carbon::now();
        $paid = $payments->where('status', 'Paid');
        $paidThisMonth = $paid->filter(fn ($p) => $p->payment_date && $p->payment_date->isSameMonth($now));

        $sheet->setCellValue('A1', ($gym?->name ?? 'FitGym').' — Branch Report');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->setCellValue('A2', 'Generated '.$now->format('F j, Y g:i A'));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('6B5D45');

        $rows = [
            ['Total members', $members->count()],
            ['Active members', $members->filter(fn ($m) => strtolower((string) $m->status) === 'active')->count()],
            ['Pending members', $members->filter(fn ($m) => strtolower((string) $m->status) === 'pending')->count()],
            ['Memberships expiring in 30 days', $members->filter(fn ($m) => $m->expiry_date && $m->expiry_date->between($now->copy()->startOfDay(), $now->copy()->addDays(30)))->count()],
            ['Total revenue (paid payments)', (float) $paid->sum('amount')],
            ['Revenue this month', (float) $paidThisMonth->sum('amount')],
            ['Pending payments', (float) $payments->where('status', 'Pending')->sum('amount')],
            ['Total check-ins', $attendance->count()],
            ['Check-ins this month', $attendance->filter(fn ($a) => $a->check_in_time && $a->check_in_time->isSameMonth($now))->count()],
        ];

        self::header($sheet, 4, ['Metric', 'Value']);
        foreach ($rows as $i => [$label, $value]) {
            $row = 5 + $i;
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            if (str_contains(strtolower($label), 'revenue') || str_contains(strtolower($label), 'payments')) {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::PESO);
            }
        }
        self::finish($sheet, 4, 4 + count($rows), 2);
    }

    private static function membersSheet(Worksheet $sheet, $members): void
    {
        $sheet->setTitle('Members');
        self::header($sheet, 1, ['Member ID', 'Full Name', 'Email', 'Phone', 'Address', 'Plan', 'Status', 'Payment Status', 'Expiry Date', 'Joined']);
        $row = 2;
        foreach ($members as $m) {
            $sheet->fromArray([
                (string) $m->member_id,
                $m->full_name,
                $m->email,
                (string) $m->phone,
                MemberAddress::street($m->address),
                $m->membershipPlan?->name ?? 'N/A',
                ucfirst(strtolower((string) $m->status)),
                ucfirst(strtolower((string) $m->payment_status)),
                $m->expiry_date?->format('Y-m-d') ?? '',
                $m->created_at?->format('Y-m-d') ?? '',
            ], null, "A{$row}", true);
            $row++;
        }
        self::finish($sheet, 1, $row - 1, 10);
    }

    private static function paymentsSheet(Worksheet $sheet, $payments): void
    {
        $sheet->setTitle('Payments');
        self::header($sheet, 1, ['Invoice', 'Member', 'Plan', 'Amount', 'Method', 'Date', 'Status', 'Notes']);
        $row = 2;
        foreach ($payments as $p) {
            $sheet->fromArray([
                $p->invoice_number,
                $p->member?->full_name ?? 'N/A',
                $p->membershipPlan?->name ?? '',
                (float) $p->amount,
                $p->payment_method,
                $p->payment_date?->format('Y-m-d') ?? '',
                $p->status,
                (string) $p->notes,
            ], null, "A{$row}", true);
            $row++;
        }
        if ($row > 2) {
            $sheet->getStyle('D2:D'.($row - 1))->getNumberFormat()->setFormatCode(self::PESO);
            $sheet->setCellValue("C{$row}", 'Total paid');
            $sheet->setCellValue("D{$row}", '=SUMIF(G2:G'.($row - 1).',"Paid",D2:D'.($row - 1).')');
            $sheet->getStyle("C{$row}:D{$row}")->getFont()->setBold(true);
            $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode(self::PESO);
        }
        self::finish($sheet, 1, max(1, $row - 1), 8);
    }

    private static function attendanceSheet(Worksheet $sheet, $attendance): void
    {
        $sheet->setTitle('Attendance');
        self::header($sheet, 1, ['Date', 'Member', 'Check-in', 'Check-out', 'Minutes', 'Status']);
        $row = 2;
        foreach ($attendance as $a) {
            $sheet->fromArray([
                $a->check_in_time?->format('Y-m-d') ?? '',
                $a->member?->full_name ?? 'N/A',
                $a->check_in_time?->format('g:i A') ?? '',
                $a->check_out_time?->format('g:i A') ?? '',
                $a->duration_minutes ?? ($a->check_in_time && $a->check_out_time ? $a->check_in_time->diffInMinutes($a->check_out_time) : ''),
                $a->check_out_time ? 'Completed' : 'In gym',
            ], null, "A{$row}", true);
            $row++;
        }
        self::finish($sheet, 1, max(1, $row - 1), 6);
    }

    private static function plansSheet(Worksheet $sheet, $plans, $members, $payments): void
    {
        $sheet->setTitle('Plans');
        self::header($sheet, 1, ['Plan', 'Type', 'Price', 'Period', 'Members', 'Revenue (paid)', 'Status']);
        $row = 2;
        foreach ($plans as $plan) {
            $sheet->fromArray([
                $plan->name,
                $plan->type,
                (float) $plan->price,
                $plan->period,
                $members->where('membership_plan_id', $plan->id)->count(),
                (float) $payments->where('membership_plan_id', $plan->id)->where('status', 'Paid')->sum('amount'),
                $plan->is_deactivated ? 'Deactivated' : 'Active',
            ], null, "A{$row}", true);
            $row++;
        }
        if ($row > 2) {
            $sheet->getStyle('C2:C'.($row - 1))->getNumberFormat()->setFormatCode(self::PESO);
            $sheet->getStyle('F2:F'.($row - 1))->getNumberFormat()->setFormatCode(self::PESO);
        }
        self::finish($sheet, 1, max(1, $row - 1), 7);
    }

    /** @param  list<string>  $titles */
    private static function header(Worksheet $sheet, int $row, array $titles): void
    {
        $sheet->fromArray($titles, null, "A{$row}");
        $last = chr(ord('A') + count($titles) - 1);
        $style = $sheet->getStyle("A{$row}:{$last}{$row}");
        $style->getFont()->setBold(true)->getColor()->setRGB(self::HEADER_INK);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    /** Borders, auto-width columns, a frozen header row and a filter on the table. */
    private static function finish(Worksheet $sheet, int $headerRow, int $lastRow, int $columns): void
    {
        $last = chr(ord('A') + $columns - 1);
        $sheet->getStyle("A{$headerRow}:{$last}{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D2C3');
        for ($i = 0; $i < $columns; $i++) {
            $sheet->getColumnDimension(chr(ord('A') + $i))->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));
        if ($lastRow > $headerRow) {
            $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastRow}");
        }
    }
}
