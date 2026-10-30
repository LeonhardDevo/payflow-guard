@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="brand-mark">{{ mb_strtoupper(mb_substr(trim(strip_tags((string) $slot)), 0, 1)) }}</span>
<span class="brand-name">{!! $slot !!}</span>
</a>
</td>
</tr>
