'use strict';
try { Object.keys(sessionStorage).filter((k) => k.startsWith('cs:')).forEach((k) => sessionStorage.removeItem(k)); } catch { /* stockage indisponible */ }
document.getElementById('formConnexion').addEventListener('submit', (event) => {
  const button = event.target.querySelector('button');
  button.setAttribute('aria-busy', 'true');
  setTimeout(() => { button.disabled = true; }, 0);
});
