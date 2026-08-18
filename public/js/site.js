// Unobtrusive confirmation for destructive actions. Any form carrying a
// data-confirm="message" attribute prompts before it submits.
//
// .NET counterpart: wwwroot/js/site.js in readlog-dotnet, copied almost verbatim.
// Keeping it out of inline markup is what lets a Content-Security-Policy use a
// strict script-src 'self' with no unsafe-inline.
(function () {
    "use strict";
    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (form instanceof HTMLFormElement && form.hasAttribute("data-confirm")) {
            if (!window.confirm(form.getAttribute("data-confirm"))) {
                event.preventDefault();
            }
        }
    });
})();
