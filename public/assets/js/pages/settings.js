/* System settings (super admin) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");

  O.api("GET", "/api/v1/settings").then(function (s) {
    var lim = s.limits;
    function num(k) {
      return { name: k, label: t("settings." + k), type: "number", req: true, int: true, hint: t("settings.range", { min: lim[k][0], max: lim[k][1] }) };
    }
    var f = O.form([
      { type: "group", label: t("settings.group_unit") },
      { name: "unit_name", label: t("settings.unit_name"), req: true, max: 100 },
      { name: "unit_phone", label: t("settings.unit_phone"), max: 50 },
      { name: "unit_address", label: t("settings.unit_address"), max: 200, span2: true },
      { type: "group", label: t("settings.group_lang") },
      { name: "default_language", label: t("settings.default_language"), type: "select", req: true, opts: s.languages.map(function (l) { return [l.code, l.name]; }), hint: t("settings.default_language_hint") },
      { type: "group", label: t("settings.group_security") },
      num("login_max_failures"), num("login_lock_minutes"), num("session_idle_minutes"), num("session_absolute_hours"), num("password_min_length"),
      { type: "group", label: t("settings.group_work") },
      { name: "work_start", label: t("settings.work_start"), req: true, max: 5, placeholder: "08:30" },
      { name: "work_end", label: t("settings.work_end"), req: true, max: 5, placeholder: "17:30" },
      { name: "work_days", label: t("settings.work_days"), type: "static", span2: true, html: [1, 2, 3, 4, 5, 6, 7].map(function (d) {
        return '<label style="margin-right:12px"><input type="checkbox" data-wd="' + d + '" style="width:auto"' + (s.work_days.indexOf(d) >= 0 ? " checked" : "") + "> " + O.esc(t("common.wd" + (d % 7))) + "</label>";
      }).join("") + '<div class="hint">' + O.esc(t("settings.work_hint")) + "</div>" }
    ], s);
    var card = document.createElement("div");
    card.className = "card";
    card.style.maxWidth = "820px";
    card.innerHTML = '<div class="card-h">' + O.esc(t("menu.settings")) + '</div><div class="card-b"></div><div class="d-f" style="border-top:1px solid var(--line)"><button type="button" class="btn pri" id="s_save">' + O.esc(t("common.save")) + "</button></div>";
    card.querySelector(".card-b").appendChild(f.el);
    view.appendChild(card);
    document.getElementById("s_save").addEventListener("click", function () {
      var v = f.values();
      v.work_days = Array.prototype.map.call(card.querySelectorAll("input[data-wd]:checked"), function (i) { return +i.getAttribute("data-wd"); });
      O.submit(f, O.api("PUT", "/api/v1/settings", v)).then(function () {
        O.status(t("common.saved"));
        if (v.default_language !== s.default_language) setTimeout(function () { window.location.reload(); }, 600);
        s.default_language = v.default_language;
      }, function (e) { if (e) O.failed(e); });
    });
  }, O.failed);
})();
