{{-- Ícones de traço simples (24×24), herdam a cor do texto. --}}
@php($d = [
  'agenda' => 'M4 6h16v15H4zM8 3v5m8-5v5M4 11h16',
  'lateral' => 'M3 4h18v16H3zM9 4v16',
  'mais' => 'M12 5v14M5 12h14',
  'exportar' => 'M12 3v12m-5-5 5 5 5-5M5 15v5h14v-5',
  'inicio' => 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z',
  'operacao' => 'M4 7h16M4 12h16M4 17h10',
  'financeiro' => 'M3 6h18v12H3zM3 10h18M7 15h3',
  'fiscal' => 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
  'contabilidade' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  'administracao' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.8 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7.3 19.6l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3.3 14H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.3-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 3.1V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.8 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.8H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1.1z',
  'plataforma' => 'M12 2 3 7v10l9 5 9-5V7zM3 7l9 5 9-5M12 12v10',
  'inteligencia' => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z',
  'ajuda' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM9.2 9a2.9 2.9 0 0 1 5.6 1c0 2-2.8 2.4-2.8 4m0 3h.01',
  'sino' => 'M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0',
][$nome] ?? '')
<svg class="icone" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $d }}"/></svg>
