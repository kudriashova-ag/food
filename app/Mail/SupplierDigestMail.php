<?php

namespace App\Mail;

use App\Exports\KitchenReportExport;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Reports\DigestPresenter;
use App\Services\Reports\KitchenReportService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Excel;

class SupplierDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Supplier $supplier,
        public readonly CarbonImmutable $date,
        public readonly array $data,
    ) {}

    public function envelope(): Envelope
    {
        $subject = sprintf(
            '%s: замовлення на %s%s',
            $this->supplier->name,
            $this->date->translatedFormat('d.m.Y'),
            $this->data['is_final'] ? '' : ' (попередньо)',
        );

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.supplier-digest',
            with: [
                'lines' => app(DigestPresenter::class)->lines($this->data, $this->date),
                'note' => trim(app(DigestPresenter::class)->statusNote($this->data)),
                'signature' => Setting::get('notification_signature', 'Шкільна їдальня'),
            ],
        );
    }

    /** Список для видачі по класах — вкладенням, бо в тіло листа він не влазить. */
    public function attachments(): array
    {
        if ($this->data['positions'] === 0) {
            return [];
        }

        $export = new KitchenReportExport(
            $this->supplier,
            $this->date,
            app(KitchenReportService::class),
        );

        return [
            Attachment::fromData(
                fn (): string => $export->raw(Excel::XLSX),
                sprintf('kuhnia-%s.xlsx', $this->date->format('Y-m-d')),
            )->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
