/**
 * Staging Theme - Intercettazione universale di navigazioni
 * 
 * Questo script intercetta TUTTI i tipi di navigazione possibili nel browser
 * e garantisce che il parametro di staging venga sempre mantenuto.
 * 
 * Intercetta:
 * - Click su qualsiasi elemento della pagina che potrebbe essere o contenere link
 * - Assegnazioni a window.location (href, assign, replace)
 * - Chiamate a window.open
 * - Risposte JSON di richieste AJAX con URL
 * - Navigazioni tramite JavaScript API
 */

(function() {
  // Disable all console logging
  if (window.console) {
    console.log = console.info = console.warn = console.error = function() {};
  }
  // Funzione per ottenere il parametro staging dall'URL o dal cookie
  function getStagingParam() {
    // Primo controllo: parametro nell'URL
    const urlParams = new URLSearchParams(window.location.search);
    const stagingParam = urlParams.get('staging');
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
  
  // Se non c'è parametro staging, non fare nulla
  if (!activeStagingParam) {
    return;
  }
  
  // Funzione per aggiungere il parametro staging a un URL
  function addStagingParam(url) {
    if (!url || typeof url !== 'string' || url.startsWith('javascript:') || url.startsWith('mailto:') || url === '#') {
      return url;
    }
    
    try {
      // Verifica se è un URL assoluto e se è sullo stesso dominio
      if (url.indexOf('http') === 0) {
        try {
          const urlObj = new URL(url);
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
      console.error('[Staging Theme] Errore durante l\'elaborazione dell\'URL:', err, url);
      return url; // In caso di errore, restituisci l'URL originale
    }
  }

  // ----------------------- INTERCETTAZIONE DI TUTTI I CLICK -----------------------

  // Intercetta qualsiasi click sulla pagina
  document.addEventListener('click', function(e) {
    // Trova il target del click o qualsiasi suo genitore che sia un link
    let target = e.target;
    let found = false;
    
    // Risali l'albero DOM fino a trovare un link o raggiungere il documento
    while (target && target !== document && !found) {
      if (target.tagName === 'A' || (target.hasAttribute && target.hasAttribute('href'))) {
        found = true;
        break;
      }
      target = target.parentNode;
    }
    
    // Se abbiamo trovato un link, procedi con la modifica
    if (found && target) {
      const href = target.getAttribute('href');
      if (href) {
        // Ignora link vuoti, javascript: o mailto:
        if (href.startsWith('javascript:') || href.startsWith('mailto:') || href === '#') {
          return; // Lascia proseguire il comportamento normale
        }
        
        // Modifica l'href con il parametro staging
        const modifiedHref = addStagingParam(href);
        if (modifiedHref !== href) {
          // Debug
          console.log('[Staging Click Interceptor] Modificato link:', href, '->', modifiedHref);
          
          // Applica la modifica
          target.setAttribute('href', modifiedHref);
        }
      }
    }
  }, true); // true = usa la fase di capturing per intercettare i click prima di altri handler
  
  // ----------------------- INTERCETTAZIONE DI WINDOW.LOCATION -----------------------
  
  // Intercetta assegnazioni dirette a window.location
  const originalWindowLocation = window.location;
  try {
    // Oggetto proxy per intercettare tutte le proprietà di window.location
    const locationProxy = new Proxy(originalWindowLocation, {
      set: function(obj, prop, value) {
        if (prop === 'href') {
          // Modifica l'URL prima di assegnarlo
          value = addStagingParam(value);
          console.log('[Staging Click Interceptor] Intercettato window.location.href:', value);
        }
        return Reflect.set(obj, prop, value);
      },
      get: function(obj, prop) {
        // Per metodi come assign e replace, restituisci versioni modificate
        if (prop === 'assign') {
          return function(url) {
            const modifiedUrl = addStagingParam(url);
            console.log('[Staging Click Interceptor] Intercettato window.location.assign:', url, '->', modifiedUrl);
            return originalWindowLocation.assign(modifiedUrl);
          };
        }
        if (prop === 'replace') {
          return function(url) {
            const modifiedUrl = addStagingParam(url);
            console.log('[Staging Click Interceptor] Intercettato window.location.replace:', url, '->', modifiedUrl);
            return originalWindowLocation.replace(modifiedUrl);
          };
        }
        
        return Reflect.get(obj, prop);
      }
    });
    
    // Sostituisci window.location con il nostro proxy
    // Disabilitato per evitare errori con proprietà non configurabili
    /*
    Object.defineProperty(window, 'location', {
      value: locationProxy,
      writable: false,
      configurable: false
    });
    */
  } catch (e) {
    console.error('[Staging Click Interceptor] Impossibile sovrascrivere window.location:', e);
    
    // Fallback: intercetta i metodi specifici
    const originalAssign = window.location.assign;
    window.location.assign = function(url) {
      return originalAssign.call(window.location, addStagingParam(url));
    };
    
    const originalReplace = window.location.replace;
    window.location.replace = function(url) {
      return originalReplace.call(window.location, addStagingParam(url));
    };
    
    // Per window.location.href dobbiamo usare un altro approccio
    let locationHref = window.location.href;
    try {
      // Disabilitato per evitare errori con proprietà non configurabili
      /*
      Object.defineProperty(window.location, 'href', {
        get: function() {
          return locationHref;
        },
        set: function(url) {
          locationHref = addStagingParam(url);
          originalAssign.call(window.location, locationHref);
          return true;
        }
      });
      */
    } catch(e) {
      console.error('[Staging Click Interceptor] Impossibile sovrascrivere window.location.href:', e);
    }
  }
  
  // ----------------------- INTERCETTAZIONE DI WINDOW.OPEN -----------------------
  
  // Intercetta window.open
  const originalOpen = window.open;
  window.open = function(url, name, specs) {
    if (url) {
      url = addStagingParam(url);
    }
    return originalOpen.call(window, url, name, specs);
  };
  
  // ----------------------- INTERCETTAZIONE DI JSON NELLE RISPOSTE AJAX -----------------------
  
  // Intercetta JSON.parse per modificare URL nelle risposte JSON
  const originalJSONParse = JSON.parse;
  JSON.parse = function(text) {
    let result;
    try {
      result = originalJSONParse.apply(this, arguments);
    } catch(e) {
      // Se c'è un errore nel parsing, restituisci il risultato originale
      return originalJSONParse.apply(this, arguments);
    }
    
    // Funzione per processare ricorsivamente oggetti e array
    function processObject(obj) {
      if (!obj || typeof obj !== 'object') return;
      
      // Se è un array, processa ogni elemento
      if (Array.isArray(obj)) {
        obj.forEach(function(item) {
          if (item && typeof item === 'object') {
            processObject(item);
          }
        });
        return;
      }
      
      // Processa ogni proprietà dell'oggetto
      Object.keys(obj).forEach(key => {
        // Se la chiave sembra contenere un URL (url, link, href, redirect, ecc.)
        if (typeof obj[key] === 'string' && 
          (key.toLowerCase().includes('url') || 
           key.toLowerCase().includes('link') || 
           key.toLowerCase().includes('href') || 
           key.toLowerCase().includes('redirect'))) {
          
          const oldUrl = obj[key];
          obj[key] = addStagingParam(oldUrl);
          
          // Debug solo se l'URL è stato effettivamente modificato
          if (obj[key] !== oldUrl) {
            console.log('[Staging Click Interceptor] Modificato URL in risposta JSON:', 
                      key, oldUrl, '->', obj[key]);
          }
        } 
        // Se è un oggetto o array, processa ricorsivamente
        else if (obj[key] && typeof obj[key] === 'object') {
          processObject(obj[key]);
        }
      });
    }
    
    // Processa il risultato se è un oggetto o un array
    if (result && typeof result === 'object') {
      processObject(result);
    }
    
    return result;
  };
  
  // ---------------------------- INTERCETTAZIONE AJAX GENERICA ----------------------------
  
  // Monitora tutte le funzioni AJAX personalizzate che potrebbero restituire URL
  document.addEventListener('DOMContentLoaded', function() {
    // Patch per qualsiasi funzione che restituisce Promise con dati
    // Questo è universale e funziona con qualsiasi funzione JavaScript che restituisce una Promise
    // e potrebbe contenere URL nei dati di risposta
    if (window.jQuery && window.jQuery.ajax) {
      const originalAjax = window.jQuery.ajax;
      window.jQuery.ajax = function() {
        const originalArgs = Array.from(arguments);
        
        // Se c'è un success callback, intercettalo
        if (originalArgs[0] && typeof originalArgs[0].success === 'function') {
          const originalSuccess = originalArgs[0].success;
          originalArgs[0].success = function(response) {
            // Controlla e modifica URL nelle risposte
            if (response && typeof response === 'object') {
              // Funzione ricorsiva per trovare e modificare URL
              (function deepModify(obj, path = '') {
                if (!obj || typeof obj !== 'object') return;
                
                Object.keys(obj).forEach(key => {
                  const currentPath = path ? path + '.' + key : key;
                  
                  if (typeof obj[key] === 'string' && 
                     (key.toLowerCase().includes('url') || 
                      key.toLowerCase().includes('link') || 
                      key.toLowerCase().includes('href') || 
                      key.toLowerCase().includes('redirect'))) {
                    
                    const oldUrl = obj[key];
                    obj[key] = addStagingParam(oldUrl);
                    
                    if (oldUrl !== obj[key]) {
                      console.log('[Staging Interceptor] Modificato URL in risposta AJAX:', 
                                currentPath, oldUrl, '->', obj[key]);
                    }
                  } 
                  else if (obj[key] && typeof obj[key] === 'object') {
                    deepModify(obj[key], currentPath);
                  }
                });
              })(response);
            }
            
            // Chiama il callback originale
            return originalSuccess.apply(this, arguments);
          };
        }
        
        return originalAjax.apply(this, originalArgs);
      };
    }
    
    // Monitora le Promise in generale, che sono usate da molte librerie moderne
    if (window.Promise) {
      // Sovrascrivi il metodo then di Promise.prototype
      const originalThen = window.Promise.prototype.then;
      window.Promise.prototype.then = function(onFulfilled, onRejected) {
        // Se c'è un handler per il successo, wrappalo
        if (typeof onFulfilled === 'function') {
          const originalOnFulfilled = onFulfilled;
          onFulfilled = function(value) {
            // Se il valore è un oggetto, cerca e modifica URL al suo interno
            if (value && typeof value === 'object') {
              // Funzione ricorsiva per cercare stringhe che sembrano URL
              function processValue(obj, path = '') {
                if (!obj || typeof obj !== 'object') return;
                
                // Se è un array, processa ogni elemento
                if (Array.isArray(obj)) {
                  obj.forEach((item, index) => {
                    if (item && typeof item === 'object') {
                      processValue(item, path ? `${path}[${index}]` : `[${index}]`);
                    }
                  });
                  return;
                }
                
                // Processa ogni proprietà dell'oggetto
                Object.keys(obj).forEach(key => {
                  const currentPath = path ? `${path}.${key}` : key;
                  
                  // Se sembra essere un URL (esaminando sia la chiave che il valore)
                  const keyLower = key.toLowerCase();
                  if (typeof obj[key] === 'string' && 
                      (keyLower.includes('url') || keyLower.includes('link') || 
                       keyLower.includes('href') || keyLower.includes('redirect') ||
                       (obj[key].includes('http') && obj[key].includes(window.location.hostname)))) {
                    
                    const oldUrl = obj[key];
                    const newUrl = addStagingParam(oldUrl);
                    
                    if (oldUrl !== newUrl) {
                      console.log('[Staging Interceptor] Modificato URL in Promise:', currentPath, oldUrl, '->', newUrl);
                      obj[key] = newUrl;
                    }
                  } 
                  else if (obj[key] && typeof obj[key] === 'object') {
                    processValue(obj[key], currentPath);
                  }
                });
              }
              
              processValue(value);
            }
            
            // Chiama l'handler originale
            return originalOnFulfilled(value);
          };
        }
        
        return originalThen.call(this, onFulfilled, onRejected);
      };
    }
  });
  
  console.log('[Staging Interceptor] Intercettazione universale attivata per parametro:', activeStagingParam);
})();