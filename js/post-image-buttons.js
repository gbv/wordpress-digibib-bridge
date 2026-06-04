window.addEventListener('load', () => {

    const widget = document.querySelector('[data-id="bb77d01"]');
    if (!widget) return;

    const img = widget.querySelector('img');
    if (!img) return;

    const contentImages      = PostImageData.contentImages      || [];
    const uncensoredImages = PostImageData.uncensoredImages || [];
    const viewer_uri = PostImageData.viewer_uri 
    const base_url = PostImageData.base_url

    // generate hidden anchors for elementor light boxes
    function buildHiddenAnchors(images, slideshowId) {
        const container = document.createElement('div');

        container.style.cssText = `
            position: fixed;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            overflow: visible;
            pointer-events: none;
        `;

        images.forEach((url, i) => {
            const a = document.createElement('a');
            a.href = url;
            a.setAttribute('data-elementor-open-lightbox', 'yes');
            a.setAttribute('data-elementor-lightbox-slideshow', slideshowId);
            a.setAttribute('data-elementor-lightbox-title', String(i + 1));
            a.setAttribute('data-elementor-lightbox-index', i);

            const img = document.createElement('img');
            img.src = url;
            img.alt = '';
            img.style.cssText = 'width:1px;height:1px;display:block;';
            a.appendChild(img);

            container.appendChild(a);
        });

        document.body.appendChild(container);
        return container;
    }

    // click on thumbnail → content-Slideshow
    if (contentImages.length > 0) {
        const contentAnchors = buildHiddenAnchors(contentImages, 'content-slideshow');
        img.style.cursor = 'pointer';
        img.addEventListener('click', () => {
            const firstAnchor = contentAnchors.querySelector('a');
            if (window.jQuery) {
                jQuery(firstAnchor).trigger('click');
            } else {
                firstAnchor.click(); // fallback
            }
    });
    }

    const btnWrap = document.createElement('div');
    btnWrap.style.cssText = `
        position: fixed;
        display: flex;
        gap: 8px;
        z-index: 9999;
    `;

    // button for uncensored images
    if (uncensoredImages.length > 0) {
        const otherAnchors = buildHiddenAnchors(uncensoredImages, 'uncensored-slideshow');

        const btnuncensored = document.createElement('button');
        btnuncensored.textContent = 'uncensored';
        btnuncensored.className = 'post-img-btn';
        btnuncensored.addEventListener('click', () => {
            if (window.jQuery) {
                jQuery(otherAnchors.querySelector('a')).trigger('click');
            } else {
                otherAnchors.querySelector('a').click();
            }
        });
        btnWrap.appendChild(btnuncensored);
    }

    // button for transcript

    if (viewer_uri) {
    const btnTranskript = document.createElement('button');
    btnTranskript.textContent = 'Transkript';
    btnTranskript.className = 'post-img-btn';
    btnTranskript.addEventListener('click', () => {

        const viewerUrl = base_url + '/rsc/viewer/' + viewer_uri ;

        const viewer = window.open('', '_blank', 'width=1200,height=800,resizable=yes');
        viewer.document.write(`
                <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <title>Transkript</title>
                        <style>
                            * { margin: 0; padding: 0; box-sizing: border-box; }
                            body { height: 100vh; overflow: hidden; }
                            iframe {
                                width: 100%;
                                height: 100vh;
                                border: none;
                            }
                        </style>
                    </head>
                    <body>
                        <iframe 
                            src="${viewerUrl}" 
                            allowfullscreen>
                        </iframe>
                    </body>
                    </html>
        `);
        viewer.document.close();

    });
    btnWrap.appendChild(btnTranskript);
    }
    

    if (btnWrap.children.length > 0) {
        document.body.appendChild(btnWrap);
    }

    function updatePosition() {
        const rect = widget.getBoundingClientRect();
        btnWrap.style.top  = `${rect.bottom + 8}px`;
        btnWrap.style.left = `${rect.left}px`;
        btnWrap.style.width = `${rect.width}px`;
    }

    updatePosition();
    window.addEventListener('scroll', updatePosition);

    const style = document.createElement('style');
    style.textContent = `
        .elementor-slideshow--ui-hidden .elementor-swiper-button-prev,
        .elementor-slideshow--ui-hidden .elementor-swiper-button-next,
        .elementor-slideshow--ui-hidden .elementor-slideshow__header,
        .elementor-slideshow--ui-hidden .elementor-slideshow__footer {
            opacity: 1 !important;
            visibility: visible !important;
        }
    `;
    document.head.appendChild(style);

}); 

