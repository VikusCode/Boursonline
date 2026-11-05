/**
 * THEME SOMBRE GLOBAL (theme.js)
 * ------------------------------
 * Ce script est auto-executé et gere le theme "Sombre" (Dark Mode) sur toutes les pages de l'application BoursOnline.
 * Il permet d'assurer une continuite visuelle (grace au localStorage) pour l'utilisateur qui navigue de page en page.
 */
(function() {
    // 1. INITIALISATION RAPIDE
    // Lecture de la preference de l'utilisateur enregistree dans le cache (localStorage).
    // On l'applique au plus vite (sur la balise HTML racine) pour eviter de faire un appel visuel net entre un mode clair d'abord, puis sombre ensuite.
    const currentTheme = localStorage.getItem('theme') || 'light';
    if (currentTheme === 'dark') {
        document.documentElement.classList.add('dark-mode');
    }

    // 2. INJECTION DES STYLES SOMBRES
    // On cree une balise <style> dynamique regroupant tous les surcharges CSS qui vont assombrir les differents composants (cartes, tableaux, boutons...) lorsque la classe .dark-mode est activee sur <html>.
    const style = document.createElement('style');
    style.innerHTML = `
        html.dark-mode body { background-color: #121212 !important; color: #e0e0e0 !important; }

        /* Conteneurs et cartes */
        html.dark-mode .card, html.dark-mode table, html.dark-mode .nav-bar, html.dark-mode .nav-links,
        html.dark-mode .transaction-card, html.dark-mode .wallet-section, html.dark-mode .portfolio-item,
        html.dark-mode header, html.dark-mode footer, html.dark-mode .profile-card, html.dark-mode .stock-card,
        html.dark-mode .chart-section, html.dark-mode .action-column, html.dark-mode .container,
        html.dark-mode .modal-content, html.dark-mode fieldset, 
        html.dark-mode .price-summary-box, html.dark-mode .buy-form, html.dark-mode .chart-wrapper {
            background-color: #1e1e1e !important; color: #e0e0e0 !important; border-color: #333 !important; box-shadow: 0 4px 24px rgba(255,255,255,0.02) !important;
        }
        
        /* Elements avec bordures specifiques ou interactions */
        html.dark-mode .period-selector { background-color: #2a2a2a !important; border: 1px solid #333 !important; }

        html.dark-mode .close-modal:hover { color: #fff !important; }

        /* Corrections de visibilite */
        html.dark-mode .budget-value[style*="color:#0984e"], 
        html.dark-mode .budget-value[style*="color: #0984e"] { color: #74b9ff !important; }

        /* Typographie */
        html.dark-mode h1, html.dark-mode h2, html.dark-mode h3, html.dark-mode h4, html.dark-mode h5, html.dark-mode h6, html.dark-mode label, html.dark-mode .stat-value, html.dark-mode strong, html.dark-mode b, html.dark-mode th,
        html.dark-mode .current-balance, html.dark-mode .price-big-display, html.dark-mode .buy-form__total-label, html.dark-mode .budget-display, html.dark-mode .or-text, html.dark-mode .price-big, html.dark-mode .back-link, html.dark-mode .back-btn, html.dark-mode .price-label, html.dark-mode legend, html.dark-mode .transaction-amount, html.dark-mode .transaction-qty {
            color: #ffffff !important;
        }

        /* Liens */
        html.dark-mode a { color: #bb86fc; }
        html.dark-mode a.action-cube { color: #ffffff !important; } 
        html.dark-mode table a, html.dark-mode th a { color: #fff !important; } 
        html.dark-mode .nav-link { color: #bb86fc; }
        html.dark-mode a:hover { color: #d1b2ff !important; }

        /* Formulaires et inputs */
        html.dark-mode input[type="file"]::file-selector-button { background-color: #333 !important; color: #e0e0e0 !important; border: 1px solid #444 !important; cursor: pointer; border-radius: 4px; padding: 4px 8px; margin-right: 10px; }
        html.dark-mode input[type="file"]::file-selector-button:hover { background-color: #444 !important; }

        html.dark-mode input, html.dark-mode select, html.dark-mode textarea, html.dark-mode .money-input {
            background-color: #2a2a2a !important; color: #fff !important; border: 1px solid #444 !important;
        }
        html.dark-mode input:focus, html.dark-mode select:focus, html.dark-mode textarea:focus, html.dark-mode .money-input:focus { 
            border-color: #bb86fc !important; outline: none; 
        }

        /* Boutons generiques */
        html.dark-mode .btn, html.dark-mode button:not([style*="fixed"]) {
            border: 1px solid #333 !important;
        }

        html.dark-mode .btn-secondary, html.dark-mode .btn-ghost { background-color: #333 !important; color: #e0e0e0 !important; }
        html.dark-mode .btn-secondary:hover { background-color: #444 !important; }
        html.dark-mode .btn-primary { background: #bb86fc !important; color: #000 !important; border: none !important; }
        html.dark-mode .btn-primary:hover { background: #a371f7 !important; }
        html.dark-mode .btn-danger { background-color: #cf6679 !important; color: #000 !important; }

        /* Boutons specifiques : Retrait/Ajout & Graphique */
        html.dark-mode .btn-deposit { background-color: #03dac6 !important; color: #000 !important; border: none !important; }
        html.dark-mode .btn-deposit:hover { background-color: #01b8a5 !important; }
        html.dark-mode .btn-withdraw { background-color: #cf6679 !important; color: #000 !important; border: none !important; }
        html.dark-mode .btn-withdraw:hover { background-color: #bd4b5e !important; }
        
        html.dark-mode .btn-period { background-color: #2a2a2a !important; color: #ccc !important; border: 1px solid #444 !important; }
        html.dark-mode .btn-period:hover { background-color: #444 !important; color: #fff !important; }
        html.dark-mode .btn-period.active { background-color: #bb86fc !important; color: #000 !important; border-color: #bb86fc !important; }

        /* Specifiques tableaux */
        html.dark-mode .divider { background-color: #333 !important; }
        html.dark-mode th, html.dark-mode td { border-bottom: 1px solid #333 !important; }
        html.dark-mode th { background-color: #2a2a2a !important; color:#fff !important; }
        html.dark-mode tr:hover td { background-color: #2c2c2c !important; }
    `;
    document.head.appendChild(style);

    // 3. CREATION DU BOUTON BASCULE (Uniquement sur l'accueil)
    document.addEventListener('DOMContentLoaded', () => {
        // On s'assure qu'on est au niveau de la racine / accueil.php pour afficher le bouton 
        // (On utilise les elements d'url et des indices de la page pour ne l'injecter qu'une seule fois au bon endroit)
const isAccueil = window.location.pathname.includes('accueil.php') || window.location.pathname.includes('mon_compte.php') || window.location.pathname.endsWith('/boursonline/') || (document.querySelector('h1') && document.querySelector('h1').innerText.includes('Bienvenue'));

        if (isAccueil) {
            const btn = document.createElement('button');
            // Fonction de mise a jour visuelle du bouton
            const updateBtnUI = (isDark) => {
                btn.style.cssText = 'position:fixed; top:20px; right:20px; z-index:9999; height:45px; border:1px solid #444; border-radius:8px; cursor:pointer; font-size:15px; font-family:\'Outfit\', sans-serif; display:inline-flex; align-items:center; justify-content:center; padding:0 20px; box-shadow:0 4px 12px rgba(0,0,0,0.15); transition:background 0.3s ease;';
                btn.innerText = isDark ? 'Thème Clair' : 'Thème Sombre';
                btn.style.background = isDark ? '#f0efed' : '#1e1e1e';
                btn.style.cssText = 'position:fixed; top:20px; right:20px; z-index:9999; height:45px; border:1px solid #444; border-radius:8px; cursor:pointer; font-size:15px; font-family:\'Outfit\', sans-serif; display:inline-flex; align-items:center; justify-content:center; padding:0 20px; box-shadow:0 4px 12px rgba(0,0,0,0.15); transition:background 0.3s ease;';
                btn.style.background = isDark ? '#f0efed' : '#1e1e1e';
                btn.style.color = isDark ? '#1a1a1a' : '#fff';
            };

            updateBtnUI(document.documentElement.classList.contains('dark-mode'));

            // Action au clic : Bascule du theme et de la preference enregistree
            btn.onclick = () => {
                const isDark = document.documentElement.classList.toggle('dark-mode');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                updateBtnUI(isDark);
            };

            document.body.appendChild(btn);
        }
    });
})();















