/**
 * Staging Theme - Script per mantenere il parametro staging nelle URL
 * 
 * Questo script rileva se c'è un parametro 'staging' nell'URL corrente
 * e lo aggiunge a tutti i link interni della pagina per mantenere
 * la visualizzazione del tema di staging durante la navigazione.
 */
document.addEventListener('DOMContentLoaded', function() {
  // Ottieni i parametri dall'URL corrente
  const urlParams = new URLSearchParams(window.location.search);
  const stagingParam = urlParams.get('staging');
  
  // Se esiste un parametro staging nell'URL corrente
  if (stagingParam) {
    // Seleziona tutti i link nella pagina
    const links = document.querySelectorAll('a');
    
    links.forEach(function(link) {
      const href = link.getAttribute('href');
      
      // Ignora link vuoti, javascript: o mailto:
      if (!href || href.startsWith('javascript:') || href.startsWith('mailto:')) {
        return;
      }
      
      // Verifica che sia un link interno (stesso dominio)
      if (href.indexOf('http') !== 0 || 
          (href.indexOf(window.location.hostname) !== -1 && href.indexOf('/wp-admin/') === -1)) {
        
        // Separa l'URL dal frammento (#)
        let url = href;
        let fragment = '';
        const fragmentIndex = href.indexOf('#');
        
        if (fragmentIndex !== -1) {
          url = href.substring(0, fragmentIndex);
          fragment = href.substring(fragmentIndex);
        }
        
        // Verifica se il link già contiene il parametro staging
        if (url.indexOf('staging=' + stagingParam) !== -1) {
          return; // Il parametro esiste già con lo stesso valore
        }
        
        // Gestisci altri parametri già presenti nell'URL
        if (url.indexOf('?') !== -1) {
          // URL contiene già altri parametri
          url += '&staging=' + stagingParam;
        } else {
          // URL non ha parametri
          url += '?staging=' + stagingParam;
        }
        
        // Riattacca il frammento
        link.setAttribute('href', url + fragment);
      }
    });
  }
});