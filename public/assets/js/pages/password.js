/* Change password */
(function () {
  "use strict";
  var O = window.OMPS;
  var f = document.getElementById("pwForm");

  function showErrors(map) {
    var ems = f.querySelectorAll("[data-err]");
    for (var i = 0; i < ems.length; i++) {
      var k = ems[i].getAttribute("data-err");
      ems[i].textContent = map && map[k] ? map[k] : "";
      ems[i].parentNode.classList.toggle("err", !!(map && map[k]));
    }
  }

  f.addEventListener("submit", function (e) {
    e.preventDefault();
    showErrors(null);
    var body = {
      old_password: document.getElementById("pw_old").value,
      new_password: document.getElementById("pw_new").value,
      confirm_password: document.getElementById("pw_confirm").value
    };
    O.api("POST", "/api/v1/me/password", body).then(function (r) {
      O.status(O.t("auth.password_changed"), "ok");
      setTimeout(function () { window.location.href = r.redirect; }, 800);
    }, function (err) {
      if (err.fields) showErrors(err.fields);
      O.status(err.message, "err");
    });
  });
})();
