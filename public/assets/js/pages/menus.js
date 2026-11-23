/* 菜单管理 (phase 9): rename (a name for every enabled language), reorder, show / hide. Pages and permissions stay as they are. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  if (!view || view.getAttribute("data-page") !== "menus") return;
  var M = null, LANGS = [], dirty = false;

  function load() {
    O.api("GET", "/api/v1/menus").then(function (d) { M = d.menu; LANGS = d.languages; dirty = false; draw(); }, O.failed);
  }

  function row(m, i, list, depth) {
    return '<div class="mm-row' + (m.enabled ? "" : " off") + '" style="padding-left:' + (12 + depth * 26) + 'px" data-path="' + depth + ":" + i + '">' +
      (depth === 0 ? O.icon(m.icon || "dot", 15) : '<span class="muted">·</span>') +
      '<span class="mm-t"><b>' + O.esc(m.title) + "</b>" + (m.custom ? ' <span class="muted" style="font-size:12px">' + O.esc(t("menu_admin.was", { name: m.default })) + "</span>" : "") +
      (m.route ? ' <span class="mono muted" style="font-size:11.5px">' + O.esc(m.route) + "</span>" : "") + "</span>" +
      '<label class="mm-sw" title="' + O.esc(m.locked ? t("menu_admin.locked") : t("menu_admin.show")) + '"><span class="switch"><input type="checkbox" data-en="1"' + (m.enabled ? " checked" : "") + (m.locked ? " disabled" : "") + "><span></span></span> " +
      O.esc(t("menu_admin.show")) + "</label>" +
      '<span class="ibs">' + (i > 0 ? '<button type="button" class="ib" data-up="1" title="' + O.esc(t("cf.up")) + '">' + O.icon("up") + "</button>" : '<span class="ib gap"></span>') +
      (i < list.length - 1 ? '<button type="button" class="ib" data-down="1" title="' + O.esc(t("cf.down")) + '">' + O.icon("down") + "</button>" : '<span class="ib gap"></span>') +
      '<button type="button" class="ib" data-rename="1" title="' + O.esc(t("menu_admin.rename")) + '">' + O.icon("edit") + "</button></span></div>";
  }

  function draw() {
    var html = '<div class="bar"><span class="muted" style="font-size:12.5px">' + O.esc(t("menu_admin.hint")) + '</span><span class="sp"></span>' +
      '<button type="button" class="btn" id="mm_reset">' + O.esc(t("menu_admin.reset")) + '</button><button type="button" class="btn pri" id="mm_save">' + O.esc(t("common.save")) + "</button></div>" +
      '<div class="card" id="mm_list" style="margin-top:12px">';
    M.forEach(function (g, gi) {
      html += '<div class="mm-group" data-g="' + gi + '">' + row(g, gi, M, 0);
      g.children.forEach(function (c, ci) { html += '<div data-c="' + ci + '">' + row(c, ci, g.children, 1) + "</div>"; });
      html += "</div>";
    });
    view.innerHTML = html + "</div>";
    view.querySelector("#mm_save").addEventListener("click", save);
    view.querySelector("#mm_reset").addEventListener("click", function () {
      O.confirm({ title: t("menu_admin.reset"), msg: t("menu_admin.reset_confirm"), ok: t("menu_admin.reset") }).then(function (ok) {
        if (ok) O.api("POST", "/api/v1/menus/reset", {}).then(function () { O.status(t("menu_admin.saved")); window.location.reload(); }, O.failed);
      });
    });
    view.querySelector("#mm_list").addEventListener("click", function (e) {
      var r = e.target.closest ? e.target.closest(".mm-row") : null;
      if (!r) return;
      var gEl = r.closest(".mm-group"), cEl = r.parentNode.getAttribute("data-c") !== null ? r.parentNode : null;
      var gi = +gEl.getAttribute("data-g"), list = cEl ? M[gi].children : M, i = cEl ? +cEl.getAttribute("data-c") : gi;
      if (e.target.closest("[data-up]")) { swap(list, i, i - 1); }
      else if (e.target.closest("[data-down]")) { swap(list, i, i + 1); }
      else if (e.target.closest("[data-rename]")) { rename(list[i]); }
    });
    view.querySelector("#mm_list").addEventListener("change", function (e) {
      if (!e.target.getAttribute("data-en")) return;
      var r = e.target.closest(".mm-row"), gEl = r.closest(".mm-group"), cEl = r.parentNode.getAttribute("data-c") !== null ? r.parentNode : null;
      var gi = +gEl.getAttribute("data-g"), item = cEl ? M[gi].children[+cEl.getAttribute("data-c")] : M[gi];
      item.enabled = e.target.checked;
      r.classList.toggle("off", !item.enabled);
      dirty = true;
    });
  }

  function swap(list, a, b) {
    var x = list[a]; list[a] = list[b]; list[b] = x;
    dirty = true;
    draw();
  }

  function rename(m) {
    var box = document.createElement("div");
    box.innerHTML = '<p class="muted" style="margin:0 0 10px">' + O.esc(t("menu_admin.rename_hint", { name: m.default })) + "</p>" + LANGS.map(function (l) {
      return '<div class="fld"><label>' + O.esc(t("menu_admin.name_in", { lang: l.name })) + '</label><input class="inp" maxlength="30" data-lang="' + O.esc(l.code) + '" value="' + O.esc(m.names[l.code] || "") + '"></div>';
    }).join("") + '<div class="err" id="mm_err" style="color:var(--bad);font-size:12.5px"></div>';
    O.modal({ title: t("menu_admin.rename") + " · " + m.default, width: 520, body: box, buttons: [
      { t: t("menu_admin.default_name"), fn: function () { m.names = {}; m.custom = false; m.title = m.default; dirty = true; draw(); } },
      { t: t("common.cancel") },
      { t: t("common.ok"), cls: "pri", fn: function () {
        var names = {}, filled = 0;
        Array.prototype.forEach.call(box.querySelectorAll("[data-lang]"), function (inp) { var v = inp.value.trim(); if (v) { names[inp.getAttribute("data-lang")] = v; filled++; } });
        if (filled && filled < LANGS.length) { box.querySelector("#mm_err").textContent = t("menu.err.names"); return Promise.reject(null); }
        m.names = names;
        m.custom = filled > 0;
        m.title = names[O.boot.lang] || m.default;
        dirty = true;
        draw();
      } }] });
  }

  function save() {
    var pack = function (m) { return { code: m.code, enabled: m.enabled, names: m.names, children: (m.children || []).map(pack) }; };
    O.api("PUT", "/api/v1/menus", { menu: M.map(pack) }).then(function () { O.status(t("menu_admin.saved")); setTimeout(function () { window.location.reload(); }, 400); }, O.failed);
  }

  window.addEventListener("beforeunload", function (e) { if (dirty) { e.preventDefault(); e.returnValue = ""; } });
  load();
})();
