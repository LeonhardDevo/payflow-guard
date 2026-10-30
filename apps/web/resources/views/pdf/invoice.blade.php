<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
@page { margin: 0; }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0B1324; }
.bar { height: 8px; background: #059669; }
.page { padding: 28px 36px 24px; }
.header { width: 100%; }
.header td { vertical-align: top; }
.brand { font-size: 16px; font-weight: bold; color: #0B1324; }
.title { font-size: 22px; font-weight: bold; text-align: right; color: #059669; }
.status { text-align: right; font-size: 11px; font-weight: bold; color: #B91C1C; }
.meta { margin-top: 4px; text-align: right; color: #475569; line-height: 1.6; }
.parties { width: 100%; margin-top: 22px; }
.parties td { width: 50%; vertical-align: top; padding-right: 16px; }
.label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #64748B; margin-bottom: 4px; }
.items { width: 100%; border-collapse: collapse; margin-top: 22px; }
.items th { background: #F1F5F9; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; padding: 6px 8px; }
.items td { padding: 7px 8px; border-bottom: 1px solid #E2E8F0; vertical-align: top; }
.num { text-align: right; white-space: nowrap; }
.totals { width: 46%; margin-left: 54%; margin-top: 12px; border-collapse: collapse; }
.totals td { padding: 4px 8px; }
.totals .grand td { border-top: 2px solid #0B1324; font-weight: bold; font-size: 12px; padding-top: 6px; }
.box { margin-top: 20px; padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 4px; }
.pre { white-space: pre-line; }
.footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #E2E8F0; color: #64748B; font-size: 8px; line-height: 1.5; }
</style>
</head>
<body>
<div class="bar"></div>
<div class="page">
  <table class="header">
    <tr>
      <td>
        <div class="brand">{{ $company['name'] }}</div>
        @if ($company['legal_name'] !== null && $company['legal_name'] !== $company['name'])<div>{{ $company['legal_name'] }}</div>@endif
      </td>
      <td>
        <div class="title">{{ __('payflow.pdf.invoice', [], $locale) }}</div>
        @if ($status_label !== null)<div class="status">{{ $status_label }}</div>@endif
        <div class="meta">
          {{ __('payflow.pdf.number', [], $locale) }}: {{ $invoice['number'] }}<br>
          {{ __('payflow.pdf.issue_date', [], $locale) }}: {{ $invoice['issue_date'] }}<br>
          {{ __('payflow.pdf.due_date', [], $locale) }}: {{ $invoice['due_date'] }}
          @if ($invoice['po_number'] !== null)<br>{{ __('payflow.pdf.po_number', [], $locale) }}: {{ $invoice['po_number'] }}@endif
        </div>
      </td>
    </tr>
  </table>

  <table class="parties">
    <tr>
      <td>
        <div class="label">{{ __('payflow.pdf.from', [], $locale) }}</div>
        <div><strong>{{ $company['legal_name'] ?? $company['name'] }}</strong></div>
        @if ($company['address'] !== null)<div class="pre">{{ $company['address'] }}</div>@endif
        @if ($company['tax_id'] !== null)<div>{{ __('payflow.pdf.tax_id', [], $locale) }}: {{ $company['tax_id'] }}</div>@endif
        @if ($company['email'] !== null)<div>{{ $company['email'] }}</div>@endif
        @if ($company['phone'] !== null)<div>{{ $company['phone'] }}</div>@endif
      </td>
      <td>
        <div class="label">{{ __('payflow.pdf.bill_to', [], $locale) }}</div>
        <div><strong>{{ $client['legal_name'] ?? $client['name'] }}</strong></div>
        @if ($client['address'] !== null)<div class="pre">{{ $client['address'] }}</div>@endif
        @if ($client['tax_id'] !== null)<div>{{ __('payflow.pdf.tax_id', [], $locale) }}: {{ $client['tax_id'] }}</div>@endif
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr>
        <th>{{ __('payflow.pdf.description', [], $locale) }}</th>
        <th class="num">{{ __('payflow.pdf.quantity', [], $locale) }}</th>
        <th class="num">{{ __('payflow.pdf.unit_price', [], $locale) }}</th>
        <th class="num">{{ __('payflow.pdf.tax_rate', [], $locale) }}</th>
        <th class="num">{{ __('payflow.pdf.amount', [], $locale) }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($items as $item)
        <tr>
          <td>{{ $item['description'] }}</td>
          <td class="num">{{ $item['quantity'] }}</td>
          <td class="num">{{ $item['unit_price'] }}</td>
          <td class="num">{{ $item['tax_rate'] }}</td>
          <td class="num">{{ $item['amount'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td>{{ __('payflow.pdf.subtotal', [], $locale) }}</td><td class="num">{{ $totals['subtotal'] }}</td></tr>
    <tr><td>{{ __('payflow.pdf.tax', [], $locale) }}</td><td class="num">{{ $totals['tax'] }}</td></tr>
    <tr class="grand"><td>{{ __('payflow.pdf.total', [], $locale) }}</td><td class="num">{{ $totals['total'] }}</td></tr>
    @if ($totals['paid'] !== null)
      <tr><td>{{ __('payflow.pdf.paid', [], $locale) }}</td><td class="num">{{ $totals['paid'] }}</td></tr>
      <tr><td><strong>{{ __('payflow.pdf.balance_due', [], $locale) }}</strong></td><td class="num"><strong>{{ $totals['balance'] }}</strong></td></tr>
    @endif
  </table>

  @if ($receiving_details !== null)
    <div class="box">
      <div class="label">{{ __('payflow.pdf.payment_details', [], $locale) }}</div>
      <div class="pre">{{ $receiving_details }}</div>
    </div>
  @endif

  @if ($pay_url !== null)
    <div class="box">
      <div class="label">{{ __('payflow.pdf.pay_online', [], $locale) }}</div>
      <div>{{ $pay_url }}</div>
    </div>
  @endif

  @if ($invoice['notes'] !== null)
    <div class="box">
      <div class="label">{{ __('payflow.pdf.notes', [], $locale) }}</div>
      <div class="pre">{{ $invoice['notes'] }}</div>
    </div>
  @endif

  @if ($invoice['terms'] !== null)
    <div class="box">
      <div class="label">{{ __('payflow.pdf.terms', [], $locale) }}</div>
      <div class="pre">{{ $invoice['terms'] }}</div>
    </div>
  @endif

  <div class="footer">
    {{ __('payflow.email.footer.sender', ['company' => $company['legal_name'] ?? $company['name']], $locale) }}<br>
    {{ __('payflow.pdf.privacy', ['contact' => $privacy_contact, 'url' => $recipients_url], $locale) }}
  </div>
</div>
</body>
</html>
