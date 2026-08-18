// The whole of ReadLog's client-side JavaScript.
//
// .NET counterpart: wwwroot/js/site.js in readlog-dotnet, which carries the first
// of these two handlers verbatim. Keeping behaviour here rather than in inline
// attributes is what lets the Content-Security-Policy use a strict
// script-src 'self' with no 'unsafe-inline'.
(function () {
    "use strict";

    // Confirmation for destructive actions. Any form carrying a
    // data-confirm="message" attribute prompts before it submits.
    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (form instanceof HTMLFormElement && form.hasAttribute("data-confirm")) {
            if (!window.confirm(form.getAttribute("data-confirm"))) {
                event.preventDefault();
            }
        }
    });

    // Submit on change, for the demo reader switcher. Without JavaScript the form
    // still works: a <noscript> submit button is rendered alongside it.
    document.addEventListener("change", function (event) {
        var control = event.target;
        if (!(control instanceof HTMLSelectElement)) {
            return;
        }

        var form = control.form;
        if (form && form.hasAttribute("data-auto-submit")) {
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    });
})();
