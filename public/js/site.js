// The whole of ReadLog's client-side JavaScript: one handler.
//
// .NET counterpart: wwwroot/js/site.js in readlog-dotnet, which carries this
// handler verbatim. A second handler here submitted the demo reader switcher on
// change, and went when Google sign-in replaced the switcher. Keeping behaviour here rather than in inline
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
})();
