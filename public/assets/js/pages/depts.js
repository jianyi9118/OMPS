/* Departments: tree on the left, details on the right */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var data = null, selId = null;

  function find(id) { return data.depts.filter(function (x) { return x.id === id; })[0]; }

  function load() {
    O.api("GET", "/api/v1/depts").then(function (r) {
      data = r;
      data.people.forEach(function (p) { p.id = +p.id; });
      if (!selId || !find(selId)) selId = data.depts.length ? data.depts[0].id : null;
      render();
    }, O.failed);
  }

  function render() {
    var c = data.caps;
    view.innerHTML = '<div class="split" style="grid-template-columns:minmax(260px,340px) minmax(0,1fr)">' +
      '<div class="card"><div class="card-h">' + O.esc(t("dept.tree")) + '<span class="r">' + (c.add && c.topLevel ? '<button type="button" class="btn sm" id="d_add_top">' + O.icon("plus", 14) + " " + O.esc(t("dept.add_top")) + "</button>" : "") + "</span></div>" +
      '<ul class="tree" id="d_tree"></ul></div><div class="card" id="d_detail"></div></div>';
    var tree = document.getElementById("d_tree");
    if (!data.depts.length) tree.innerHTML = '<li class="muted" style="padding:16px">' + O.esc(t("dept.empty")) + "</li>";
    data.depts.forEach(function (x) {
      var li = document.createElement("li");
      li.innerHTML = '<div class="node' + (x.id === selId ? " on" : "") + '" data-id="' + x.id + '" style="padding-left:' + (12 + x.depth * 18) + 'px"><span></span>' +
        (x.status === "disabled" ? " " + O.pill(t("status.disabled"), "") : "") + '<span class="cnt">' + x.people + "</span></div>";
      li.querySelector("span").textContent = x.name;
      tree.appendChild(li);
    });
    tree.addEventListener("click", function (e) {
      var n = e.target.closest ? e.target.closest(".node") : null;
      if (n) { selId = +n.getAttribute("data-id"); render(); }
    });
    var top = document.getElementById("d_add_top");
    if (top) top.addEventListener("click", function () { deptForm(null, null); });
    renderDetail();
  }

  function renderDetail() {
    var box = document.getElementById("d_detail");
    var x = selId ? find(selId) : null;
    if (!x) { box.innerHTML = '<div class="card-b muted">' + O.esc(t("dept.select_hint")) + "</div>"; return; }
    var c = data.caps;
    var parent = x.parent_id ? find(x.parent_id) : null;
    var btns = [];
    if (c.add) btns.push('<button type="button" class="btn" data-do="addChild">' + O.icon("plus", 14) + " " + O.esc(t("dept.add_child")) + "</button>");
    if (c.edit) {
      btns.push('<button type="button" class="btn" data-do="up" title="' + O.esc(t("dept.move_up")) + '">' + O.icon("up", 14) + "</button>");
      btns.push('<button type="button" class="btn" data-do="down" title="' + O.esc(t("dept.move_down")) + '">' + O.icon("down", 14) + "</button>");
      btns.push('<button type="button" class="btn" data-do="edit">' + O.icon("edit", 14) + " " + O.esc(t("common.edit")) + "</button>");
    }
    if (c.del) btns.push('<button type="button" class="btn danger" data-do="del">' + O.icon("trash", 14) + " " + O.esc(t("common.delete")) + "</button>");
    var rows = [
      [t("dept.field.name"), O.esc(x.name)], [t("dept.field.code"), '<span class="mono">' + O.esc(x.code) + "</span>"],
      [t("dept.field.parent"), O.esc(parent ? parent.name : t("dept.top_level"))], [t("dept.field.leader"), O.esc(x.leader || "—")],
      [t("dept.people"), String(x.people)], [t("dept.field.status"), x.status === "active" ? O.pill(t("status.active"), "ok") : O.pill(t("status.disabled"), "")],
      [t("dept.field.remark"), O.esc(x.remark || "—")]
    ];
    box.innerHTML = '<div class="card-h"><span></span><span class="r">' + btns.join("") + '</span></div><div class="card-b"><table class="tbl"><tbody>' +
      rows.map(function (r) { return '<tr><th style="width:140px">' + O.esc(r[0]) + "</th><td>" + r[1] + "</td></tr>"; }).join("") + "</tbody></table>" +
      '<p style="margin-top:12px"><a class="btn" href="' + O.esc(O.url("/org/staff", { dept_id: x.id })) + '">' + O.esc(t("dept.view_staff")) + "</a></p></div>";
    box.querySelector(".card-h span").textContent = x.name;
    box.querySelector(".card-h").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-do]") : null;
      if (!b) return;
      var a = b.getAttribute("data-do");
      if (a === "addChild") deptForm(null, x);
      else if (a === "edit") deptForm(x, null);
      else if (a === "up" || a === "down") O.api("POST", "/api/v1/depts/" + x.id + "/move", { dir: a }).then(load, O.failed);
      else if (a === "del") del(x);
    });
  }

  function parentOpts(self) {
    return data.depts.filter(function (d) {
      if (!self) return true;
      if (d.id === self.id) return false;
      var p = d;
      while (p && p.parent_id) { if (p.parent_id === self.id) return false; p = find(p.parent_id); }
      return true;
    }).map(function (d) { return [d.id, new Array(d.depth + 1).join("   ") + d.name]; });
  }

  function deptForm(x, parent) {
    var fields = [
      { name: "name", label: t("dept.field.name"), req: true, max: 100 },
      { name: "code", label: t("dept.field.code"), max: 50, hint: x ? "" : t("dept.code_hint") },
      { name: "parent_id", label: t("dept.field.parent"), type: "select", int: true, empty: t("dept.top_level"), req: !data.caps.topLevel, noEmpty: !data.caps.topLevel, opts: parentOpts(x) },
      { name: "leader_person_id", label: t("dept.field.leader"), type: "select", int: true, opts: data.people.map(function (p) { return [p.id, p.name + " · " + p.staff_no]; }) },
      { name: "status", label: t("dept.field.status"), type: "radio", hide: !x, req: true, opts: [["active", t("status.active")], ["disabled", t("status.disabled")]] },
      { name: "remark", label: t("dept.field.remark"), type: "textarea", max: 500 }
    ];
    var vals = x ? { name: x.name, code: x.code, parent_id: x.parent_id, leader_person_id: x.leader_person_id, status: x.status, remark: x.remark } : { parent_id: parent ? parent.id : "" };
    var f = O.form(fields, vals);
    O.modal({ title: x ? t("dept.edit") : (parent ? t("dept.add_child_of", { name: parent.name }) : t("dept.add_top")), width: 600, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var v = f.values();
      var p = x ? O.api("PUT", "/api/v1/depts/" + x.id, v) : O.api("POST", "/api/v1/depts", v);
      return O.submit(f, p).then(function (r) {
        if (!x && r && r.id) selId = r.id;
        O.status(t("common.saved"));
        load();
      });
    } }] });
  }

  function del(x) {
    O.confirm({ title: t("dept.delete"), msg: t("dept.delete_confirm", { name: x.name }), ok: t("common.delete"), danger: true }).then(function (ok) {
      if (!ok) return;
      O.api("DELETE", "/api/v1/depts/" + x.id).then(function () { selId = x.parent_id; O.status(t("common.deleted")); load(); }, function (err) {
        if (err.status === 409) O.info(t("dept.cannot_delete"), "<p>" + O.esc(err.message) + "</p>");
        else O.failed(err);
      });
    });
  }

  load();
})();
