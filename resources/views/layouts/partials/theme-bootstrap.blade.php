{{--
    Aplica o tema antes da primeira pintura para evitar flash.

    Usuários autenticados recebem `data-theme` renderizado pelo servidor
    a partir de `users.theme_preference`. Visitantes usam o padrão claro e
    recuperam a escolha feita no navegador (localStorage).
--}}
<script>
    (function () {
        var root = document.documentElement;
        var theme = root.dataset.theme === 'dark' ? 'dark' : 'light';

        if (root.dataset.themeUser !== '1') {
            try {
                var stored = window.localStorage.getItem('arcana.theme');

                if (stored === 'dark' || stored === 'light') {
                    theme = stored;
                }
            } catch (error) {
                // Armazenamento bloqueado: mantém o padrão claro do servidor.
            }

            root.dataset.theme = theme;
        }

        root.style.colorScheme = theme;
    })();
</script>
