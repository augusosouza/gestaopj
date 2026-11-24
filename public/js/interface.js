const painel = document.querySelector('[data-menu-preferencia]');

if (painel) {
    const chave = painel.dataset.menuPreferencia;
    const botao = painel.querySelector('.lateral-compactar');
    const menuMobile = painel.querySelector('#menu');
    const desktop = matchMedia('(min-width: 901px)');
    const atalhoMobile = painel.querySelector('[data-menu-mobile]');
    const lateral = painel.querySelector('#menu-principal');
    let focoAnterior = menuMobile;
    atalhoMobile.hidden = false;

    function atualizarMenuMobile() {
        atalhoMobile.setAttribute('aria-expanded', String(menuMobile.checked));
        if (menuMobile.checked) lateral.querySelector('a[href]')?.focus();
        else if (!desktop.matches) focoAnterior.focus();
    }

    atalhoMobile.addEventListener('click', () => {
        focoAnterior = atalhoMobile;
        menuMobile.checked = !menuMobile.checked;
        atualizarMenuMobile();
    });
    menuMobile.addEventListener('change', () => {
        if (menuMobile.checked) focoAnterior = menuMobile;
        atualizarMenuMobile();
    });
    const grupos = Array.from(painel.querySelectorAll('.menu-grupo'));
    const ler = (nome, padrao) => {
        try { return JSON.parse(localStorage.getItem(nome)) ?? padrao; } catch { return padrao; }
    };
    const gravar = (nome, valor) => {
        try { localStorage.setItem(nome, JSON.stringify(valor)); } catch { /* Preferência indisponível. */ }
    };
    const salvos = ler(chave + '.grupos', []);
    let compacto = ler(chave + '.compacto', false) === true;

    function atualizarMenu() {
        const recolhido = desktop.matches && compacto;
        painel.classList.toggle('menu-compacto', recolhido);
        botao.hidden = !desktop.matches;
        botao.setAttribute('aria-expanded', String(!recolhido));
        const nome = recolhido ? 'Expandir navegação' : 'Recolher navegação';
        botao.setAttribute('aria-label', nome);
        botao.title = nome;
    }

    botao.addEventListener('click', () => {
        compacto = !compacto;
        gravar(chave + '.compacto', compacto);
        atualizarMenu();
    });
    desktop.addEventListener('change', () => {
        menuMobile.checked = false;
        atalhoMobile.setAttribute('aria-expanded', 'false');
        atualizarMenu();
    });
    document.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape' && menuMobile.checked) {
            menuMobile.checked = false;
            atualizarMenuMobile();
        }
    });
    for (const grupo of grupos) {
        if (Array.isArray(salvos) && salvos.includes(grupo.dataset.grupo)) grupo.open = true;
        grupo.addEventListener('toggle', () => gravar(chave + '.grupos',
            grupos.filter((item) => item.open).map((item) => item.dataset.grupo)));
        const resumo = grupo.querySelector('summary');
        resumo.title = resumo.querySelector('span').textContent;
        resumo.addEventListener('click', (evento) => {
            if (!painel.classList.contains('menu-compacto')) return;
            evento.preventDefault();
            grupo.open = true;
            compacto = false;
            gravar(chave + '.compacto', compacto);
            atualizarMenu();
        });
    }
    atualizarMenu();
}

for (const abas of document.querySelectorAll('[data-abas]')) {
    const ativa = abas.querySelector('[aria-current="page"]');
    if (ativa && abas.scrollWidth > abas.clientWidth) {
        abas.scrollLeft = abas.scrollLeft + ativa.getBoundingClientRect().left - abas.getBoundingClientRect().left - (abas.clientWidth - ativa.offsetWidth) / 2;
    }
}

for (const formulario of document.querySelectorAll('[data-evitar-reenvio]')) {
    const botao = formulario.querySelector('button[type=submit], button:not([type])');
    const texto = botao.textContent;
    formulario.addEventListener('submit', (evento) => {
        if (botao.disabled) {
            evento.preventDefault();
            return;
        }
        botao.disabled = true;
        botao.textContent = 'Registrando…';
    });
    window.addEventListener('pageshow', () => {
        botao.disabled = false;
        botao.textContent = texto;
    });
}

for (const menu of document.querySelectorAll('.exportar-menu')) {
    menu.addEventListener('keydown', (evento) => {
        if (evento.key !== 'Escape' || !menu.open) return;
        menu.open = false;
        menu.querySelector('summary').focus();
    });
}

const painelBaixa = document.querySelector('[data-painel-baixa]');
if (painelBaixa && typeof painelBaixa.showModal === 'function') {
    const conteudo = painelBaixa.querySelector('[data-conteudo-baixa]');
    let origem = null;
    let resumo = null;

    function abrirBaixa(detalhes) {
        if (painelBaixa.open) return;
        origem = detalhes;
        resumo = detalhes.querySelector('summary');
        const formulario = detalhes.querySelector('form');
        detalhes.open = false;
        conteudo.append(formulario);
        painelBaixa.showModal();
        formulario.querySelector('select').focus();
    }

    painelBaixa.querySelector('[data-fechar-baixa]').addEventListener('click', () => painelBaixa.close());
    painelBaixa.addEventListener('close', () => {
        origem.append(conteudo.firstElementChild);
        resumo.focus({preventScroll: true});
    });
    for (const detalhes of document.querySelectorAll('[data-baixa-rapida]')) {
        const gatilho = detalhes.querySelector('summary');
        gatilho.setAttribute('aria-haspopup', 'dialog');
        gatilho.addEventListener('click', (evento) => {
            evento.preventDefault();
            abrirBaixa(detalhes);
        });
        if (detalhes.hasAttribute('data-reabrir-baixa')) abrirBaixa(detalhes);
    }
}
