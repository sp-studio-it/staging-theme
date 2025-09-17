/**
 * Staging Theme - Script per mantenere il parametro staging nelle URL
 * 
 * Questo script rileva se c'è un parametro 'staging' nell'URL corrente
 * e lo aggiunge a tutti i link interni della pagina per mantenere
 * la visualizzazione del tema di staging durante la navigazione.
 * 
 * Versione 2.0: Soluzione avanzata con intercettazione di tutte le navigazioni
 */
(function() { // IIFE per inizializzare subito senza attendere DOMContentLoaded
  // Inizializzazione immediata per catturare anche eventi precoci
  // e funzioni definite prima del caricamento del DOM
  // Ottieni i parametri dall'URL corrente
  const urlParams = new URLSearchParams(window.location.search);
  const stagingParam = urlParams.get('staging');
  
  // Verifica anche il cookie in caso il parametro nell'URL non sia presente
  function getStagingParam() {
    // Primo controllo: parametro nell'URL
    if (stagingParam) {
      return stagingParam;
    }
    
    // Secondo controllo: cookie
    const cookieValue = document.cookie
      .split('; ')
      .find(row => row.startsWith('staging_version='))
      ?.split('=')[1];
    
    return cookieValue || null;
  }
  
  const activeStagingParam = getStagingParam();
  
  // Se esiste un parametro staging nell'URL o nel cookie
  if (activeStagingParam) {
    // Funzione per aggiungere il parametro staging a un URL
    window.addStagingParam = function(url) {
      // Ignora URL vuoti, javascript: o mailto:
      if (!url || typeof url !== 'string' || url.startsWith('javascript:') || url.startsWith('mailto:')) {
        return url;
      }
      
      try {
        // Verifica se è un URL assoluto
        let isAbsolute = false;
        let urlObj;
        
        if (url.indexOf('http') === 0) {
          try {
            urlObj = new URL(url);
            isAbsolute = true;
            
            // Verifica se è un dominio esterno
            if (urlObj.hostname !== window.location.hostname) {
              return url; // Non modificare URL esterni
            }
          } catch (e) {
            // URL malformato, tentiamo comunque di processarlo
          }
        }
        
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
        if (urlPart.indexOf('staging=' + activeStagingParam) !== -1) {
          return url; // Il parametro esiste già con lo stesso valore
        }
        
        // Gestisci altri parametri già presenti nell'URL
        if (urlPart.indexOf('?') !== -1) {
          // URL contiene già altri parametri
          urlPart += '&staging=' + activeStagingParam;
        } else {
          // URL non ha parametri
          urlPart += '?staging=' + activeStagingParam;
        }
        
        // Riattacca il frammento
        return urlPart + fragment;
      } catch (err) {
        // Errore durante l'elaborazione dell'URL
        return url; // In caso di errore, restituisci l'URL originale
      }
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
    
    // Disabilitato per evitare errori con proprietà non configurabili
    /*
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
    */
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
      // Modifica l'URL per tutte le richieste, non solo GET
      if (url && typeof url === 'string') {
        url = window.addStagingParam(url);
      }
      return originalXHROpen.call(this, method, url, async === undefined ? true : async, user, password);
    };
    
    // Intercettazione speciale per JSON in risposta AJAX
    // Questo è cruciale per i callback che utilizzano URL dalle risposte JSON
    const originalJSONParse = JSON.parse;
    JSON.parse = function(text) {
      const result = originalJSONParse.apply(this, arguments);
      
      // Funzione ricorsiva per ispezionare e modificare gli URL nei dati JSON
      function processObject(obj) {
        if (!obj || typeof obj !== 'object') return;
        
        Object.keys(obj).forEach(key => {
          // Se la chiave sembra essere un URL
          if (typeof obj[key] === 'string' && 
             (key.includes('url') || key.includes('link') || key.includes('href') || key.includes('redirect'))) {
            obj[key] = window.addStagingParam(obj[key]);
          } 
          // Se è un oggetto o array, processa ricorsivamente
          else if (typeof obj[key] === 'object' && obj[key] !== null) {
            processObject(obj[key]);
          }
        });
      }
      
      // Processa solo se è un oggetto o un array
      if (typeof result === 'object' && result !== null) {
        processObject(result);
      }
      
      return result;
    };
    
    // Monitora specificamente l'oggetto response.data.edit_url nel pattern di Untitled-1
    // Patch globale per tutte le risposte AJAX che contengono URL
    const patchAjaxResponses = function() {
      if (window.jQuery) {
        const originalAjaxSuccess = window.jQuery.ajax;
        
        window.jQuery.ajax = function() {
          // Memorizziamo gli argomenti originali
          const originalArgs = Array.prototype.slice.call(arguments);
          
          // Se c'è un success callback, lo intercettiamo
          if (originalArgs[0] && typeof originalArgs[0].success === 'function') {
            const originalSuccess = originalArgs[0].success;
            
            originalArgs[0].success = function(response) {
              // Controlla e modifica specificamente le risposte con edit_url
              if (response && response.data && response.data.edit_url) {
                response.data.edit_url = window.addStagingParam(response.data.edit_url);
              }
              
              // Chiama il callback originale
              return originalSuccess.apply(this, arguments);
            };
          }
          
          return originalAjaxSuccess.apply(this, originalArgs);
        };
        
        // Patch anche per altri metodi jQuery comuni
        if (window.jQuery.fn) {
          // Intercetta i click su elementi A
          const originalClick = window.jQuery.fn.click;
          window.jQuery.fn.click = function() {
            if (arguments.length === 0 && this[0] && this[0].tagName === 'A') {
              const href = this.attr('href');
              if (href) {
                this.attr('href', window.addStagingParam(href));
              }
            }
            return originalClick.apply(this, arguments);
          };
        }
      }
    };
    
    // Aggiungi il patch quando jQuery è pronto
    if (window.jQuery) {
      patchAjaxResponses();
    } else {
      // jQuery non è ancora caricato, attendiamo
      const checkJQuery = setInterval(function() {
        if (window.jQuery) {
          clearInterval(checkJQuery);
          patchAjaxResponses();
        }
      }, 50);
    }
    
    // Patch specifico per WooCommerce AJAX
    document.addEventListener('DOMContentLoaded', function() {
      // Monitora tutte le chiamate fetch in modo specifico per WooCommerce
      if (typeof window.wp !== 'undefined' && window.wp.hooks) {
        // WooCommerce usa wp.hooks per alcune operazioni AJAX
        try {
          window.wp.hooks.addFilter('woocommerce_get_endpoint_url', 'staging-theme', function(url) {
            return window.addStagingParam(url);
          });
        } catch(e) {
          // WP Hooks non disponibile
        }
      }
      
      // WooCommerce fragment refresh
      const originalFragmentRefresh = window.wc_cart_fragments_params && window.wc_cart_fragments_params.ajax_url;
      if (originalFragmentRefresh) {
        window.wc_cart_fragments_params.ajax_url = window.addStagingParam(originalFragmentRefresh);
      }
    });
    
    // Esponi la funzione di aggiunta del parametro staging a livello globale
    // per permettere a plugin/script esterni di usarla direttamente
    window.stagingTheme = window.stagingTheme || {};
    window.stagingTheme.addStagingParam = window.addStagingParam;
    window.stagingTheme.stagingParam = activeStagingParam;
    
    // Aggiungi una funzione di monitoraggio per debug
    window.stagingTheme.debug = function(info) {
      // Debug function placeholder
    };
    
    // Comunica agli script esterni che il sistema di staging è attivo
    const event = new CustomEvent('stagingThemeReady', { 
      detail: { stagingParam: activeStagingParam } 
    });
    document.dispatchEvent(event);
    
    // Aggiungi evento click globale per tutti gli elementi
    document.addEventListener('click', function(e) {
      // Se l'elemento cliccato è o è dentro un link
      let target = e.target;
      while (target && target !== document) {
        if (target.tagName === 'A') {
          const href = target.getAttribute('href');
          if (href && !href.startsWith('javascript:') && !href.startsWith('mailto:')) {
            const modifiedHref = window.addStagingParam(href);
            if (modifiedHref !== href) {
              target.setAttribute('href', modifiedHref);
            }
          }
          break;
        }
        target = target.parentNode;
      }
    }, true);
    
    // Aggiunge un override per qualsiasi script che usa returnUrl o redirect_to
    // Questo è comune in molti plugin WooCommerce
    document.addEventListener('DOMContentLoaded', function() {
      // Patch per WooCommerce
      if (window.wc_add_to_cart_params) {
        if (window.wc_add_to_cart_params.cart_url) {
          window.wc_add_to_cart_params.cart_url = window.addStagingParam(window.wc_add_to_cart_params.cart_url);
        }
        if (window.wc_add_to_cart_params.wc_ajax_url) {
          window.wc_add_to_cart_params.wc_ajax_url = window.addStagingParam(window.wc_add_to_cart_params.wc_ajax_url);
        }
      }
      
      // Cerca e correggi tutti gli URL nelle variabili JavaScript globali
      for (const key in window) {
        if (window.hasOwnProperty(key) && key.indexOf('_url') > -1 && typeof window[key] === 'string') {
          window[key] = window.addStagingParam(window[key]);
        }
      }
    });
    
  // Staging attivo con parametro: "' + activeStagingParam + '"
    } else {
      // Modalità staging non attiva
  }

  // Anche se non c'è parametro staging, rendiamo disponibile la funzione per uso futuro
  window.stagingTheme = window.stagingTheme || {};
  window.stagingTheme.getStagingParam = getStagingParam;
})();