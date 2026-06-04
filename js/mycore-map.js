
( function () {
    'use strict';

    // Wait for DOM 
    document.addEventListener( 'DOMContentLoaded', init );

    let map         = null;
    let allMarkers  = [];    // [{ data, marker }]
    let activeCategory = '';

    function init() {
        const mapEl = document.getElementById( 'mycore-map' );
        if ( ! mapEl ) return;

        const cfg = window.MyCoreMapConfig || {};

        map = L.map( 'mycore-map', {
            scrollWheelZoom: false,
            minZoom: 7,
            maxZoom: 18,
            zoomControl: false
        } ).setView(
            [ parseFloat( cfg.centerLat ), parseFloat( cfg.centerLng )],
            parseInt( cfg.zoom )
        );

        // Tile layer
        L.tileLayer( cfg.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        } ).addTo( map );
        loadCountyOutlines();

        // Fetch data
        fetchMapData( cfg );

        //add a button to set zoom back
        const initialView = {
            lat: parseFloat(cfg.centerLat),
            lng: parseFloat(cfg.centerLng),
            zoom: parseInt(cfg.zoom)
        };

        const ResetControl = L.Control.extend({
            options: {
                position: 'topleft'
            },

            onAdd: function () {
                const btn = L.DomUtil.create('button', 'mycore-reset-btn');
                btn.innerHTML = 'Zoom zurücksetzen';

                // Prevent map interactions when clicking button
                L.DomEvent.disableClickPropagation(btn);

                btn.onclick = function () {
                    map.setView(
                        [initialView.lat, initialView.lng],
                        initialView.zoom
                    );
                };

                return btn;
            }
        });

        map.addControl(new ResetControl());
    }

    //  Fetch map data from REST endpoint
    function fetchMapData( cfg ) {
        const params = new URLSearchParams( { post_type: cfg.postType || 'post' } );
        const url    = ( cfg.apiUrl || '/wp-json/mycore/v1/map-data' ) + '?' + params;

        fetch( url, {
            headers: {
                'X-WP-Nonce': cfg.nonce || '',
            },
        } )
        .then( r => r.json() )
        .then( data => {
            buildMarkers( data );
            buildCategoryFilters( data );
        } )
        .catch( err => {
            console.error( 'MyCoRe Map: Failed to load map data', err );
            showMapError();
        } );
    } 

    function loadCountyOutlines() {
    fetch( window.MyCoreMapConfig.pluginUrl + 'geo/NDS_Landesflaeche.geojson' )
        .then( r => r.json() )
        .then( data => {
            L.geoJSON( data, {
                style: {
                    color: '#9D6A1D',
                    weight: 2,
                  //  fillColor: '#c8daf5',
                   fillOpacity: 0,
                }
            }).addTo( map );
        });

    fetch( window.MyCoreMapConfig.pluginUrl + 'geo/NDS_Landkreise.geojson' )
        .then( r => r.json() )
        .then( data => {
            L.geoJSON( data, {
                style: {
                    color: '#555555',
                    weight: 1,
                   // fillColor: '#a8c4e8',
                   fillOpacity: 0,
                },
                onEachFeature: ( feature, layer ) => {
                    // Tooltip mit Kreisname beim Hovern (falls Attribut vorhanden)
                    if ( feature.properties ) {
                        const name = feature.properties.GEN        // häufiges Attribut bei NDS-Daten
                                  || feature.properties.name
                                  || feature.properties.NAME;
                        if ( name ) layer.bindTooltip( name );
                    }
                }
            }).addTo( map );
        });
    }

    // Build Leaflet markers 
    function buildMarkers( items ) {
        allMarkers = [];

        // Create a cluster group instead of adding markers directly to the map
        const clusterGroup = L.markerClusterGroup({
            maxClusterRadius: 50,        // px radius within which markers get clustered
            spiderfyOnMaxZoom: true,     // spread markers out at max zoom instead of clustering
            showCoverageOnHover: false,  // don't show the cluster boundary polygon on hover
            zoomToBoundsOnClick: true,   // click cluster to zoom into it
        });

        items.forEach( item => {
            const marker = L.marker( [ item.lat, item.lng ] );
            marker.bindPopup(buildPopupHtml(item), {
                autoPan: true,
                autoPanPadding: [50, 50], // extra space around popup
                maxWidth: 300,            // prevent overly wide popups
            });
            marker.on('click', function (e) {
                const popup = e.target.getPopup();

                setTimeout(() => {
                    map.panInside(popup.getLatLng(), {
                        padding: [60, 60]
                    });
                }, 100); // wait for popup to render
            });
            clusterGroup.addLayer( marker );  // add to cluster, not directly to map
            allMarkers.push( { data: item, marker } );
        });

        map.addLayer( clusterGroup );

        // Store reference so applyFilters can refresh the cluster
        window._myCoreClusterGroup = clusterGroup;
    }

    function buildPopupHtml(item) {
        const thumb = item.thumbnail
            ? `<div class="mycore-popup-image">
                    <img src="${escHtml(item.thumbnail)}" alt="" />
            </div>`
            : '';

        const cats = Object.entries(item.categories || {})
            .flatMap(([type, values]) => values.map(v =>
                `<span class="mycore-popup-cat"><em>${escHtml(type)}:</em> ${escHtml(v)}</span>`
            ))
            .join('');

        return `
            <div class="mycore-popup">
                <div class="mycore-popup-content">
                    <div class="mycore-popup-text">
                        <h3 class="mycore-popup-title">
                            <a href="${escHtml(item.permalink)}">${escHtml(item.title)}</a>
                        </h3>
                        ${item.geo_label ? `<p class="mycore-popup-geo">📍 ${escHtml(item.geo_label)}</p>` : ''}
                        ${cats ? `<div class="mycore-popup-cats">${cats}</div>` : ''}
                    </div>
                    ${thumb}
                </div>
            </div>`;
    }

    //  Category filter buttons 
let activeFilters = {};

// Build dropdown filter bar 
function buildCategoryFilters( items ) {
    const filterBar = document.getElementById( 'mycore-map-filters' );
    if ( ! filterBar ) return;

    // Collect all unique values per category type
    const types = [ 'Zeitraum', 'Thema', 'Quellenart', 'Kontinent' ];
    const valueMap = {};  // { 'Zeitraum': Set{...}, ... }

    types.forEach( t => valueMap[t] = new Set() );

    items.forEach( item => {
        const cats = item.categories || {};
        types.forEach( t => {
            ( cats[t] || [] ).forEach( v => { if (v) valueMap[t].add(v); } );
        });
    });

    // Hide bar if no category data at all
    const hasAny = types.some( t => valueMap[t].size > 0 );
    if ( ! hasAny ) { filterBar.style.display = 'none'; return; }

    filterBar.innerHTML = '';

    types.forEach( type => {
        const values = [ ...valueMap[type] ].sort();
        if ( values.length === 0 ) return;

        activeFilters[type] = new Set();

        const wrapper = document.createElement('div');
        wrapper.className = 'mycore-filter-dropdown';

        // Toggle button
        const toggle = document.createElement('button');
        toggle.className = 'mycore-filter-toggle';
        toggle.dataset.type = type;
        toggle.innerHTML = `${ type } <span class="mycore-filter-arrow">▾</span>`;
        toggle.setAttribute('aria-expanded', 'false');

        // Dropdown panel
        const panel = document.createElement('div');
        panel.className = 'mycore-filter-panel';
        panel.hidden = true;

        // One checkbox per value
        values.forEach( val => {
            const label = document.createElement('label');
            label.className = 'mycore-filter-option';

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = val;
            cb.dataset.type = type;

            cb.addEventListener('change', () => {
                if ( cb.checked ) {
                    activeFilters[type].add(val);
                } else {
                    activeFilters[type].delete(val);
                }
                updateToggleBadge( toggle, type );
                applyFilters();
            });

            label.appendChild(cb);
            label.appendChild( document.createTextNode( ' ' + val ) );
            panel.appendChild(label);
        });

        // Open/close toggle
        toggle.addEventListener('click', () => {
            const isOpen = !panel.hidden;
            // Close all other panels first
            filterBar.querySelectorAll('.mycore-filter-panel').forEach( p => {
                p.hidden = true;
            });
            filterBar.querySelectorAll('.mycore-filter-toggle').forEach( b => {
                b.setAttribute('aria-expanded','false');
                b.classList.remove('open');
            });
            if ( !isOpen ) {
                panel.hidden = false;
                toggle.setAttribute('aria-expanded','true');
                toggle.classList.add('open');
            }
        });

        wrapper.appendChild(toggle);
        wrapper.appendChild(panel);
        filterBar.appendChild(wrapper);
    });

    // Reset button
    const resetBtn = document.createElement('button');
    resetBtn.className = 'mycore-filter-reset';
    resetBtn.textContent = '✕ Filter zurücksetzen';
    resetBtn.style.display = 'none';
    resetBtn.addEventListener('click', () => {
        // Uncheck all checkboxes
        filterBar.querySelectorAll('input[type=checkbox]').forEach( cb => cb.checked = false );
        // Clear all active filters
        types.forEach( t => activeFilters[t] = new Set() );
        // Reset badges
        filterBar.querySelectorAll('.mycore-filter-toggle').forEach( b => updateToggleBadge(b, b.dataset.type) );
        resetBtn.style.display = 'none';
        applyFilters();
    });
    filterBar.appendChild(resetBtn);

    // Close panels when clicking outside
    document.addEventListener('click', e => {
        if ( !filterBar.contains(e.target) ) {
            filterBar.querySelectorAll('.mycore-filter-panel').forEach( p => p.hidden = true );
            filterBar.querySelectorAll('.mycore-filter-toggle').forEach( b => {
                b.setAttribute('aria-expanded','false');
                b.classList.remove('open');
            });
        }
    });
}

function updateToggleBadge( toggleBtn, type ) {
    const count = activeFilters[type]?.size || 0;
    const filterBar = document.getElementById('mycore-map-filters');
    const resetBtn  = filterBar?.querySelector('.mycore-filter-reset');

    // Update badge on button
    let badge = toggleBtn.querySelector('.mycore-filter-badge');
    if ( count > 0 ) {
        if ( !badge ) {
            badge = document.createElement('span');
            badge.className = 'mycore-filter-badge';
            toggleBtn.appendChild(badge);
        }
        badge.textContent = count;
    } else {
        badge?.remove();
    }

    // Show/hide reset button
    if ( resetBtn ) {
        const anyActive = Object.values(activeFilters).some( s => s.size > 0 );
        resetBtn.style.display = anyActive ? 'inline-block' : 'none';
    }
}

function applyFilters() {
    const cluster = window._myCoreClusterGroup;
    if ( !cluster ) return;

    cluster.clearLayers();

    allMarkers.forEach( ({ data, marker }) => {
        if ( isPostVisible( data ) ) {
            cluster.addLayer( marker );
        }
    });

    // Re-run search on top of filters
    const searchVal = document.getElementById('mycore-search')?.value?.trim() || '';
    if ( searchVal.length >= 2 ) runSearch( searchVal );
}

function isPostVisible( data ) {
    const cats = data.categories || {};
    return Object.entries( activeFilters ).every( ([type, selected]) => {
        if ( selected.size === 0 ) return true;  
        const postVals = cats[type] || [];
        return postVals.some( v => selected.has(v) );
    });
}

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function showMapError() {
        const mapEl = document.getElementById( 'mycore-map' );
        if ( mapEl ) {
            mapEl.innerHTML = '<p style="padding:2rem;text-align:center;">Map could not be loaded. Please try again later.</p>';
        }
    }

} )();
