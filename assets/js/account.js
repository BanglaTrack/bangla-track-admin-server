(function () {
    'use strict';

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'absolute';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        document.body.removeChild(area);
    }

    function setCopiedState(button) {
        var copyLabel = (window.btServerAccount && window.btServerAccount.copyLabel) || 'Copy';
        var copiedLabel = (window.btServerAccount && window.btServerAccount.copiedLabel) || 'Copied';

        button.textContent = copiedLabel;
        button.disabled = true;

        window.setTimeout(function () {
            button.textContent = copyLabel;
            button.disabled = false;
        }, 1400);
    }

    function bindCopyHandlers() {
        var buttons = document.querySelectorAll('.bt-copy-license-key');
        if (!buttons.length) {
            return;
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-license') || '';
                if (!key) {
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(key).then(function () {
                        setCopiedState(button);
                    })["catch"](function () {
                        fallbackCopy(key);
                        setCopiedState(button);
                    });
                    return;
                }

                fallbackCopy(key);
                setCopiedState(button);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCopyHandlers);
        return;
    }

    bindCopyHandlers();
})();
