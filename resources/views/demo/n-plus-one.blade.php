<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>N+1 · Eloquent Query Lab</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f5fa;color:#18243b;font:17px/1.7 "Segoe UI",Tahoma,sans-serif}main{max-width:1180px;margin:36px auto;padding:0 24px}h1{margin:0;font-size:36px}p{margin:8px 0 20px}.tag{color:#526580;font-size:13px;letter-spacing:2px}.panel{background:white;border:1px solid #dce2ed;border-radius:16px;padding:24px;margin:20px 0}.controls{display:flex;gap:12px;align-items:center;flex-wrap:wrap}a,button{display:inline-block;padding:9px 17px;border:1px solid #cad4e4;border-radius:9px;text-decoration:none;color:#203959;background:white;font:inherit;cursor:pointer}.active{background:#213e79;color:white;border-color:#213e79}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.metric{background:white;padding:20px;border:1px solid #dce2ed;border-radius:14px}.metric strong{display:block;font-size:42px;color:{{ $mode === 'lazy' ? '#bc432e' : '#15765d' }}}pre{direction:ltr;text-align:left;background:#122037;color:#dce8fa;border-radius:10px;padding:18px;overflow:auto;font-size:14px;white-space:pre-wrap}table{width:100%;border-collapse:collapse;text-align:right}th,td{padding:10px;border-bottom:1px solid #e3e8f1}small{color:#586b86}.query{direction:ltr;text-align:left;border-bottom:1px solid #e3e8f1;padding:12px 0;overflow-wrap:anywhere}.query code{font-size:13px}.badge{color:#526580;font-size:13px} @media(max-width:650px){.metrics{grid-template-columns:1fr}h1{font-size:27px}}
    </style>
</head>
<body><main>
    <div class="tag" dir="ltr">ELOQUENT / QUERY LAB</div>
    <h1>N+1 & Eager Loading</h1>
    <p>نفس الحجوزات، طرق تحميل مختلفة — استعلامات فعلية من Eloquent على SQLite مؤقتة ببيانات تجريبية.</p>
    <div class="panel">
        <div class="controls">
            @foreach (['lazy' => '① قبل · Lazy Loading', 'eager' => '② بعد · Eager Loading', 'count' => '③ عدد المستندات · withCount'] as $value => $label)
                <a class="{{ $mode === $value ? 'active' : '' }}" href="?mode={{ $value }}&count={{ $count }}">{{ $label }}</a>
            @endforeach
        </div>
        <div class="controls" style="margin-top:16px"><span>عدد الحجوزات:</span>
            @foreach ([5, 20, 50] as $size)
                <a class="{{ $count === $size ? 'active' : '' }}" href="?mode={{ $mode }}&count={{ $size }}">{{ $size }}</a>
            @endforeach
        </div>
    </div>
    <div class="metrics">
        <div class="metric">عدد الاستعلامات الفعلي<strong>{{ $query_count }}</strong><small>{{ $mode === 'lazy' ? '1 + (3 × '.$count.')' : ($mode === 'eager' ? '1 للحجوزات + 3 للعلاقات' : 'استعلام واحد يتضمن COUNT subquery') }}</small></div>
        <div class="metric">عدد الحجوزات<strong>{{ $count }}</strong><small>{{ $mode === 'count' ? '3 مستندات لكل حجز' : 'عميل ومورد وموعد مختلفون لكل حجز' }}</small></div>
        <div class="metric">زمن SQL بالمللي ثانية<strong>{{ $sql_ms }}</strong><small>الجلب وتجهيز البيانات: {{ $elapsed }} ms</small></div>
    </div>
    <div class="panel">
        @if ($mode === 'lazy')
            <h2>① إثبات المشكلة</h2><p>جبنا الحجوزات فقط. عند قراءة العميل والمورد والموعد لكل حجز، Eloquent ينفّذ 3 استعلامات إضافية. جرّب 5 ثم 20: العدد يزيد من 16 إلى 61.</p>
            <pre>Booking::query()->without(['customer', 'resource', 'slot'])->limit({{ $count }})->get();
// Inside the loop: $booking->customer, $booking->resource, $booking->slot</pre>
        @elseif ($mode === 'eager')
            <h2>② إثبات الحل</h2><p>نفس الحقول ونفس النتائج. العلاقات بتتحمّل على دفعات باستخدام IN؛ جرّب تغيير العدد: الاستعلامات تفضل 4، لكن حجم البيانات يزيد.</p>
            <pre>Booking::query()->with([
    'customer:id,name',
    'resource:id,name',
    'slot:id,start_time,end_time',
])->limit({{ $count }})->get();</pre>
        @else
            <h2>③ لما نحتاج العدد فقط</h2><p>استخدمنا علاقة documents الموجودة في المشروع. قاعدة البيانات بتحسب عدد المستندات لكل حجز من غير تحميل موديلات المستندات. دي تجربة مستقلة، والرد هنا بيحتوي العدد فقط.</p>
            <pre>Booking::query()->without(['customer', 'resource', 'slot'])
    ->withCount('documents')->limit({{ $count }})->get();
// $booking->documents_count</pre>
        @endif
        <small>الموديل الأصلي فيه eager loading تلقائي؛ بنعطّله فقط في تجربة «قبل» وتجربة العدّ. القياس يستبعد تجهيز البيانات وطلبات الصفحة. أزمنة SQLite المحلية ليست قياسًا لأداء شبكة أو بيئة الإنتاج.</small>
    </div>
    <div class="panel"><h2>البيانات الناتجة</h2><table><thead><tr><th>الحجز</th>@if($mode === 'count')<th>عدد المستندات</th>@else<th>العميل</th><th>المورد</th><th>الموعد</th>@endif</tr></thead><tbody>
        @foreach($rows as $row)<tr><td>#{{ $row['id'] }}</td>@if($mode === 'count')<td>{{ $row['documents_count'] }}</td>@else<td>{{ $row['customer'] }}</td><td>{{ $row['resource'] }}</td><td dir="ltr">{{ $row['start_time'] }} – {{ $row['end_time'] }}</td>@endif</tr>@endforeach
    </tbody></table></div>
    <div class="panel"><h2>SQL الفعلي · {{ $query_count }} استعلام</h2><p>كل سطر هنا استعلام تم تنفيذه فعلًا. القيم المربوطة معروضة أسفله.</p>
        @foreach($queries as $query)<div class="query"><span class="badge">#{{ $loop->iteration }} · {{ $query['time'] }} ms</span><br><code>{{ $query['query'] }}</code><br><small>Bindings: {{ json_encode($query['bindings']) }}</small></div>@endforeach
    </div>
</main></body></html>
