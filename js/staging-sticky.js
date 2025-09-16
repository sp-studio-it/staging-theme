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
    // Funzione per aggiungere il parametro staging a un URL
    window.addStagingParam = function(url) {
      // Ignora URL vuoti, javascript: o mailto:
      if (!url || typeof url !== 'string' || url.startsWith('javascript:') || url.startsWith('mailto:')) {
        return url;
      }
      
      // Verifica che sia un link interno (stesso dominio o relativo)
      if (url.indexOf('http') !== 0 || url.indexOf(window.location.hostname) !== -1) {
        // Ignora link all'area admin
        if (url.indexOf('/wp-admin/') !== -1) {
          return url;
        }
        
        // Separa l'URL dal frammento (#)
        let urlPart = url;
        let fragment = '';
        const fragmentIndex = url.indexOf('#');
        
        if (fragmentIndex !== -1) {
          urlPart = url.substring(0, fragmentIndex);
          fragment = url.substring(fragmentIndex);
        }
        
        // Verifica se il link già contiene il parametro staging
        if (urlPart.indexOf('staging=' + stagingParam) !== -1) {
          return url; // Il parametro esiste già con lo stesso valore
        }
        
        // Gestisci altri parametri già presenti nell'URL
        if (urlPart.indexOf('?') !== -1) {
          // URL contiene già altri parametri
          urlPart += '&staging=' + stagingParam;
        } else {
          // URL non ha parametri
          urlPart += '?staging=' + stagingParam;
        }
        
        // Riattacca il frammento
        return urlPart + fragment;
      }
      
      return url;
    };
    
    // Monitora le navigazioni tramite window.location
    const originalAssign = window.location.assign;
    window.location.assign = function(url) {
      return originalAssign.call(window.location, window.addStagingParam(url));
    };
    
    const originalReplace = window.location.replace;
    window.location.replace = function(url) {
      return originalReplace.call(window.location, window.addStagingParam(url));
    };
    
    // Sovrascrive window.open per aggiungere il parametro staging
    const originalOpen = window.open;
    window.open = function(url, name, specs) {
      return originalOpen.call(window, window.addStagingParam(url), name, specs);
    };
    
    // Sorveglia eventuali assegnazioni a window.location.href
    let locationHref = window.location.href;
    Object.defineProperty(window.location, 'href', {
      get: function() {
        return locationHref;
      },
      set: function(url) {
        locationHref = window.addStagingParam(url);
        originalAssign.call(window.location, locationHref);
        return true;
      }
    });
    // Seleziona tutti i link nella pagina
    const links = document.querySelectorAll('a');
    
    links.forEach(function(link) {
      const href = link.getAttribute('href');
      
      // Utilizziamo la nostra nuova funzione per aggiungere il parametro staging
      const modifiedHref = window.addStagingParam(href);
      if (modifiedHref !== href) {
        link.setAttribute('href', modifiedHref);
      }
    });
    
    // Monitora le modifiche al DOM per aggiungere il parametro staging a nuovi link
    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'childList') {
          mutation.addedNodes.forEach(function(node) {
            if (node.nodeType === 1) { // Solo elementi DOM
              // Cerca tutti i link nell'elemento aggiunto
              const newLinks = node.querySelectorAll('a');
              newLinks.forEach(function(link) {
                const href = link.getAttribute('href');
                const modifiedHref = window.addStagingParam(href);
                if (modifiedHref !== href) {
                  link.setAttribute('href', modifiedHref);
                }
              });
              
              // Controlla se l'elemento stesso è un link
              if (node.tagName === 'A') {
                const href = node.getAttribute('href');
                const modifiedHref = window.addStagingParam(href);
                if (modifiedHref !== href) {
                  node.setAttribute('href', modifiedHref);
                }
              }
            }
          });
        }
      });
    });
    
    // Avvia il monitoraggio dell'intero documento
    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
    
    // Intercetta i form submit per aggiungere il parametro staging alle azioni dei form
    document.addEventListener('submit', function(e) {
      const form = e.target;
      if (form && form.tagName === 'FORM') {
        // Ignora form che puntano all'area admin
        const action = form.getAttribute('action');
        if (action && action.indexOf('/wp-admin/') !== -1) {
          return;
        }
        
        // Aggiungi il parametro staging all'action del form se non è già presente
        if (action) {
          const modifiedAction = window.addStagingParam(action);
          form.setAttribute('action', modifiedAction);
        } else {
          // Se non c'è action, il form invia alla stessa pagina
          // Aggiungiamo un campo nascosto con il parametro staging
          let hasStaging = false;
          const inputs = form.querySelectorAll('input[name="staging"]');
          inputs.forEach(function(input) {
            if (input.value === stagingParam) {
              hasStaging = true;
            }
          });
          
          if (!hasStaging) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'staging';
            hiddenInput.value = stagingParam;
            form.appendChild(hiddenInput);
          }
        }
      }
    }, true); // true per catturare l'evento nella fase di capturing
    
    // Intercetta la History API
    if (window.history && window.history.pushState) {
      const originalPushState = window.history.pushState;
      window.history.pushState = function(state, title, url) {
        // Aggiungi il parametro staging all'URL se non è già presente
        if (url) {
          url = window.addStagingParam(url);
        }
        return originalPushState.call(window.history, state, title, url);
      };
      
      const originalReplaceState = window.history.replaceState;
      window.history.replaceState = function(state, title, url) {
        // Aggiungi il parametro staging all'URL se non è già presente
        if (url) {
          url = window.addStagingParam(url);
        }
        return originalReplaceState.call(window.history, state, title, url);
      };
    }
    
    // Intercetta le chiamate fetch per aggiungere il parametro staging alle URL
    if (window.fetch) {
      const originalFetch = window.fetch;
      window.fetch = function(resource, options) {
        if (typeof resource === 'string') {
          // È un URL, aggiungi il parametro staging
          resource = window.addStagingParam(resource);
        } else if (resource instanceof Request) {
          // È un oggetto Request, crea una nuova Request con l'URL modificato
          const url = window.addStagingParam(resource.url);
          resource = new Request(url, resource);
        }
        return originalFetch.call(window, resource, options);
      };
    }
    
    // Intercetta XMLHttpRequest per aggiungere il parametro staging
    const originalXHROpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
      // Modifica l'URL solo per le richieste GET e se è un URL interno
      if (url && (method.toUpperCase() === 'GET' || !method)) {
        url = window.addStagingParam(url);
      }
      return originalXHROpen.call(this, method, url, async === undefined ? true : async, user, password);
    };
    
    // Esponi la funzione di aggiunta del parametro staging a livello globale
    // per permettere a plugin/script esterni di usarla direttamente
    window.stagingTheme = window.stagingTheme || {};
    window.stagingTheme.addStagingParam = window.addStagingParam;
    window.stagingTheme.stagingParam = stagingParam;
    
    // Comunica agli script esterni che il sistema di staging è attivo
    document.dispatchEvent(new CustomEvent('stagingThemeReady', { 
      detail: { stagingParam: stagingParam } 
    }));
    
    console.log('Staging Theme: modalità staging attiva con parametro "' + stagingParam + '"');
  }
});