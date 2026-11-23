@php($s = $f->situacao())
<span @class(['tag', 'ok' => $s === 'paga', 'bad' => $s === 'vencida', 'warn' => $s === 'fechada', 'info' => in_array($s, ['aberta', 'futura'], true)])>{{ ['paga' => 'Paga', 'vencida' => 'Vencida', 'fechada' => 'Fechada · a pagar', 'aberta' => 'Aberta', 'futura' => 'Futura', 'vazia' => 'Sem compras'][$s] }}</span>
