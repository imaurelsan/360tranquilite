(function () {
    var html = document.documentElement;
    if (!html || typeof html.className !== 'string') {
        return;
    }

    if (html.className.indexOf('no-js') !== -1) {
        html.className = html.className.replace(/\bno-js\b/g, 'js').trim();
    }
})();
