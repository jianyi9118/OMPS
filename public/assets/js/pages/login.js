/* Sign-in page */
(function () {
  "use strict";
  var O = window.OMPS;
  var f = document.getElementById("loginForm");
  var err = document.getElementById("lg_err");
  var btn = document.getElementById("lg_btn");

  f.addEventListener("submit", function (e) {
    e.preventDefault();
    err.hidden = true;
    var u = document.getElementById("lg_user").value.trim();
    var p = document.getElementById("lg_pass").value;
    if (!u || !p) {
      err.textContent = O.t("auth.enter_credentials");
      err.hidden = false;
      return;
    }
    btn.disabled = true;
    O.api("POST", "/api/v1/auth/login", { username: u, password: p }).then(function (r) {
      window.location.href = r.redirect;
    }, function (e2) {
      btn.disabled = false;
      err.textContent = e2.message;
      err.hidden = false;
      document.getElementById("lg_pass").value = "";
      document.getElementById("lg_pass").focus();
    });
  });

  document.getElementById("lg_lang").addEventListener("change", function () {
    O.api("POST", "/api/v1/lang", { lang: this.value }).then(function () { window.location.reload(); }, O.failed);
  });
})();
