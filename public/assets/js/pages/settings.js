/* System settings (super admin): one form at full width; the section bar on top scrolls to each part */
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
      }).join("") + '<div class="hint">' + O.esc(t("settings.work_hint")) + "</div>" },
      { type: "group", label: t("holiday.group") },
      { name: "holidays", label: "", type: "static", span2: true, html: '<div id="s_hol"></div>' }
    ], s);
    /* a bar of the sections (stays at the top while scrolling; the save button lives there too) and the form at full width */
    var nav = document.createElement("div");
    nav.className = "set-nav";
    var card = document.createElement("div");
    card.className = "card set-form";
    card.innerHTML = '<div class="card-b"></div>';
    card.querySelector(".card-b").appendChild(f.el);
    view.appendChild(nav);
    view.appendChild(card);
    var groups = Array.prototype.slice.call(card.querySelectorAll(".gtitle"));
    nav.innerHTML = '<div class="subnav" role="tablist">' + groups.map(function (g, i) {
      return '<button type="button" data-sec="' + i + '">' + O.esc(g.textContent) + "</button>"; }).join("") +
      '<span class="sp"></span><button type="button" class="btn pri" id="s_save">' + O.esc(t("common.save")) + "</button></div>";
    var scroller = view.closest(".content") || document.scrollingElement;
    var tabs = nav.querySelectorAll("[data-sec]");
    function mark(i) { for (var k = 0; k < tabs.length; k++) tabs[k].classList.toggle("on", k === i); }
    function spy() {
      var top = nav.getBoundingClientRect().bottom + 12, cur = 0;
      groups.forEach(function (g, i) { if (g.getBoundingClientRect().top <= top) cur = i; });
      // at the very bottom the last (short) section cannot reach the top: it is the one in view
      if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 2) cur = groups.length - 1;
      mark(cur);
    }
    var lock = 0;
    nav.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-sec]") : null;
      if (!b) return;
      var g = groups[+b.getAttribute("data-sec")];
      mark(+b.getAttribute("data-sec"));
      lock = Date.now();
      // scroll only the content area (scrollIntoView would also move the page frame)
      var y = scroller.scrollTop + g.getBoundingClientRect().top - nav.getBoundingClientRect().bottom - 10;
      if (scroller.scrollTo) scroller.scrollTo({ top: Math.max(0, y), behavior: "smooth" }); else scroller.scrollTop = Math.max(0, y);
    });
    scroller.addEventListener("scroll", function () { if (Date.now() - lock > 700) spy(); });
    mark(0);
    holidays(document.getElementById("s_hol"), new Date().getFullYear());
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

  /* 节假日与调休: saved at once (not with the form's save button) */
  function holidays(el, year) {
    O.api("GET", "/api/v1/holidays", null, { year: year }).then(function (d) {
      el.innerHTML = '<div class="hol-bar"><button type="button" class="btn sm" data-y="-1" aria-label="' + O.esc(t("holiday.prev_year")) + '">‹</button><b>' +
        O.esc(t("plan.tl.year", { y: d.year })) + '</b><button type="button" class="btn sm" data-y="1" aria-label="' + O.esc(t("holiday.next_year")) + '">›</button>' +
        '<span class="muted">' + O.esc(t("holiday.workdays", { n: d.workdays })) + '</span><span class="sp"></span><button type="button" class="btn sm" data-add="1">' +
        O.icon("plus", 14) + " " + O.esc(t("holiday.add")) + "</button></div>";
      el.appendChild(O.table({
        cols: [
          { t: t("holiday.field.name"), f: function (r) { return O.esc(r.name); } },
          { t: t("holiday.field.kind"), f: function (r) { return O.pill(t("holiday.kind." + r.kind), r.kind === "off" ? "ok" : "warn"); } },
          { t: t("holiday.col.dates"), f: function (r) { return '<span class="mono">' + O.esc(r.start === r.end ? r.start : r.start + " – " + r.end) + "</span>"; } },
          { t: t("holiday.col.days"), cls: "num", f: function (r) { return O.esc(t("holiday.days", { n: r.days })); } }
        ],
        rows: d.rows, empty: t("holiday.empty"), slots: 2,
        acts: function () {
          return [{ icon: "edit", tip: t("common.edit"), fn: function (r) { form(r); } }, { icon: "trash", tip: t("common.delete"), danger: true, fn: function (r) { remove(r); } }];
        }
      }));
      el.insertAdjacentHTML("beforeend", '<div class="hint">' + O.esc(t("holiday.hint")) + "</div>");
      el.onclick = function (e) {
        var b = e.target.closest ? e.target.closest("button") : null;
        if (!b) return;
        if (b.hasAttribute("data-y")) holidays(el, d.year + +b.getAttribute("data-y"));
        if (b.hasAttribute("data-add")) form(null);
      };
      function form(r) {
        var f = O.form([
          { name: "name", label: t("holiday.field.name"), req: true, max: 50, placeholder: t("holiday.name_ph") },
          { name: "kind", label: t("holiday.field.kind"), type: "radio", req: true, opts: [["off", t("holiday.kind.off")], ["work", t("holiday.kind.work")]] },
          { name: "start", label: t("holiday.field.start"), type: "date", req: true },
          { name: "end", label: t("holiday.field.end"), type: "date", hint: t("holiday.end_hint") }
        ], r || { kind: "off" });
        O.modal({ title: t(r ? "holiday.edit" : "holiday.add_title"), width: 520, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
          var v = f.values();
          if (!v.end) v.end = v.start;
          return O.submit(f, O.api(r ? "PUT" : "POST", "/api/v1/holidays" + (r ? "/" + r.id : ""), v)).then(function () {
            O.status(t("common.saved"));
            holidays(el, +v.start.slice(0, 4) || d.year);
          });
        } }] });
      }
      function remove(r) {
        O.confirm({ title: t("holiday.delete"), msg: t("holiday.delete_confirm", { name: r.name }), danger: true, ok: t("common.delete") }).then(function (ok) {
          if (ok) O.api("DELETE", "/api/v1/holidays/" + r.id).then(function () { O.status(t("common.deleted")); holidays(el, d.year); }, O.failed);
        });
      }
    }, O.failed);
  }
})();
