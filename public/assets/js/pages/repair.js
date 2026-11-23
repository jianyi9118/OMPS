/* 故障与维修 (phase 6): fault reports (assign, finish, cancel) · repair records */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t, D = O.device;
  var view = document.getElementById("view");
  var state = { tab: O.qs("tab") === "repairs" ? "repairs" : "faults", status: "active", kw: "", page: 1 };
  var focusId = +O.qs("id") || 0;
  var M = null, repairers = [];

  function shell() {
    view.innerHTML = '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="rp_tabs"><button type="button" data-tab="faults">' + O.esc(t("device.rep.faults")) +
      '</button><button type="button" data-tab="repairs">' + O.esc(t("device.rep.repairs")) + '</button></div><div id="rp_body" style="display:grid;gap:12px"></div>';
    document.getElementById("rp_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { state.tab = b.getAttribute("data-tab"); state.page = 1; state.kw = ""; render(); }
    });
  }

  function render() {
    var bs = document.querySelectorAll("#rp_tabs button");
    for (var i = 0; i < bs.length; i++) bs[i].classList.toggle("on", bs[i].getAttribute("data-tab") === state.tab);
    var chips = state.tab === "faults" ? '<div class="chips">' + [["active", t("device.rep.f_active")], ["fixed", t("device.fault_status.fixed")], ["cancelled", t("device.fault_status.cancelled")], ["", t("device.rep.f_all")]].map(function (c) {
      return '<button type="button" class="chip' + (state.status === c[0] ? " on" : "") + '" data-st="' + c[0] + '">' + O.esc(c[1]) + "</button>"; }).join("") + "</div>" : "";
    document.getElementById("rp_body").innerHTML = '<div class="bar">' + chips + '<input type="search" id="rp_kw" placeholder="' + O.esc(t("device.rep.search")) + '" value="' + O.esc(state.kw) + '">' +
      '<span class="sp"></span><button type="button" class="btn" data-a="reportFault">' + O.icon("wrench", 14) + " " + O.esc(t("device.report.title")) + '</button></div><div class="card" id="rp_card"></div>';
    var bar = document.querySelector("#rp_body .bar");
    bar.addEventListener("click", function (e) { var b = e.target.closest ? e.target.closest("[data-st]") : null; if (b) { state.status = b.getAttribute("data-st"); state.page = 1; render(); } });
    document.getElementById("rp_kw").addEventListener("input", O.debounce(function (e) { state.kw = e.target.value.trim(); state.page = 1; load(); }, 350));
    load();
  }

  function load() {
    var url = state.tab === "faults" ? "/api/v1/device-faults" : "/api/v1/device-repairs";
    O.api("GET", url, null, { status: state.status, kw: state.kw, page: state.page }).then(function (res) {
      if (res.repairers) repairers = res.repairers;
      var card = document.getElementById("rp_card");
      if (!card) return;
      card.innerHTML = "";
      var cols = [
        { t: t("device.fault.reported_at"), f: function (f) { return '<span class="mono">' + O.esc(f.reported_at.slice(0, 16)) + "</span>"; } },
        { t: t("device.col.device"), cls: "wrap", f: function (f) { return '<a class="lk" href="#" data-dev="' + f.device_id + '">' + O.esc(f.asset_no + " " + f.device) + "</a>" + (f.location ? '<div class="muted" style="font-size:12px">' + O.esc(f.location) + "</div>" : ""); } },
        { t: t("device.fault.description"), cls: "wrap", f: function (f) { return O.esc(f.description.slice(0, 80)) + '<div class="muted" style="font-size:12px">' + O.esc(t("device.fault.by", { name: f.reporter })) + "</div>"; } }
      ];
      if (state.tab === "faults") {
        cols.push({ t: t("device.fault.status"), f: function (f) { return D.faultPill(f.status) + (f.repair ? '<div class="muted" style="font-size:12px">' + O.esc(f.repair.repairer) + "</div>" : ""); } });
      } else {
        cols.push({ t: t("device.fault.repairer"), f: function (f) { return O.esc(f.repair ? f.repair.repairer : ""); } });
        cols.push({ t: t("device.fault.fixed_at"), f: function (f) { return '<span class="mono">' + O.esc(f.repair ? f.repair.fixed_at.slice(0, 16) : "") + "</span>"; } });
        cols.push({ t: t("device.fault.cost"), cls: "num", f: function (f) { return O.esc(f.repair && f.repair.cost ? f.repair.cost : "—"); } });
        cols.push({ t: t("device.fault.result"), cls: "wrap", f: function (f) { return O.esc(f.repair ? f.repair.result : ""); } });
      }
      var tw = O.table({ cols: cols, rows: res.rows, empty: t(state.tab === "faults" ? "device.rep.f_empty" : "device.rep.r_empty"),
        acts: function (f) {
          var e = M.caps.edit;
          return [
            { icon: "eye", tip: t("common.view"), fn: function (x) { show(x.id); } },
            e && f.status === "open" ? { icon: "wrench", tip: t("device.rep.assign"), pri: true, fn: function (x) { assign(x); } } : (e && f.status === "repairing" ? { icon: "check", tip: t("device.rep.finish"), pri: true, fn: function (x) { finish(x.id); } } : null),
            e && (f.status === "open" || f.status === "repairing") ? { icon: "x", tip: t("device.rep.cancel"), danger: true, fn: function (x) { cancel(x); } } : null
          ];
        }, slots: 3 });
      card.appendChild(tw);
      card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
      if (state.tab === "faults") card.insertAdjacentHTML("beforeend", O.legend([["eye", t("common.view")], ["wrench", t("device.rep.assign")], ["check", t("device.rep.finish")], ["x", t("device.rep.cancel")]]));
      else card.insertAdjacentHTML("beforeend", '<div class="card-b muted" style="font-size:12.5px">' + O.esc(t("device.rep.total_cost", { cost: res.cost })) + "</div>");
      card.onclick = function (e) { var a = e.target.closest ? e.target.closest("[data-dev]") : null; if (a) { e.preventDefault(); D.detail(+a.getAttribute("data-dev"), { tab: 4 }); } };
    }, O.failed);
  }

  function show(id) {
    O.api("GET", "/api/v1/device-faults/" + id).then(function (f) {
      var el = document.createElement("div");
      el.style.display = "grid";
      el.style.gap = "10px";
      el.innerHTML = '<div class="fgrid"><div class="fld"><label>' + O.esc(t("device.col.device")) + '</label><div class="ro">' + O.esc(f.asset_no + " " + f.device + (f.location ? " · " + f.location : "")) + "</div></div>" +
        '<div class="fld"><label>' + O.esc(t("device.fault.status")) + '</label><div class="ro">' + D.faultPill(f.status) + "</div></div>" +
        '<div class="fld"><label>' + O.esc(t("device.fault.reporter")) + '</label><div class="ro">' + O.esc(f.reporter + " · " + f.reported_at.slice(0, 16)) + "</div></div>" +
        (f.repair ? '<div class="fld"><label>' + O.esc(t("device.fault.repairer")) + '</label><div class="ro">' + O.esc(f.repair.repairer + " · " + f.repair.started_at.slice(0, 16)) + "</div></div>" : "") +
        '<div class="fld span2"><label>' + O.esc(t("device.fault.description")) + '</label><div class="ro">' + D.para(f.description) + "</div></div>" +
        (f.repair && f.repair.fixed_at ? '<div class="fld span2"><label>' + O.esc(t("device.fault.result")) + '</label><div class="ro">' + D.para(f.repair.result) +
          (f.repair.cost ? '<div class="muted">' + O.esc(t("device.fault.cost_is", { cost: f.repair.cost })) + "</div>" : "") + "</div></div>" : "") +
        (f.cancel_reason ? '<div class="fld span2"><label>' + O.esc(t("device.fault.cancel_reason")) + '</label><div class="ro">' + O.esc(f.cancel_reason) + "</div></div>" : "") +
        '<div class="fld span2"><label>' + O.esc(t("device.fault.photos")) + "</label>" + (D.filesHtml(f.files) || '<span class="muted">' + O.esc(t("file.none")) + "</span>") + "</div></div>";
      var btns = [];
      if (M.caps.edit && f.status === "open") btns.push({ t: t("device.rep.assign"), cls: "pri", fn: function () { assign(f); } });
      if (M.caps.edit && f.status === "repairing") btns.push({ t: t("device.rep.finish"), cls: "pri", fn: function () { finish(f.id); } });
      if (M.caps.edit && (f.status === "open" || f.status === "repairing")) btns.push({ t: t("device.rep.cancel"), cls: "danger", fn: function () { cancel(f); } });
      btns.push({ t: t("common.close") });
      O.modal({ title: t("device.rep.fault_title"), width: 720, body: el, buttons: btns });
      O.pulse();
    }, O.failed);
  }

  function assign(f) {
    var form = O.form([
      { name: "repairer_user_id", label: t("device.fault.repairer"), type: "select", int: true, empty: t("device.rep.outside"), opts: repairers.map(function (r) { return [r.id, r.name]; }) },
      { name: "repairer_name", label: t("device.fault.repairer_name"), max: 100, hint: t("device.rep.outside_hint") }
    ], {});
    O.modal({ title: t("device.rep.assign_title", { device: f.asset_no + " " + f.device }), width: 560, body: form.el, buttons: [{ t: t("common.cancel") }, { t: t("device.rep.assign"), cls: "pri", fn: function () {
      return O.submit(form, O.api("POST", "/api/v1/device-faults/" + f.id + "/assign", form.values())).then(function () { O.status(t("device.rep.assigned")); load(); });
    } }] });
  }

  function finish(id) {
    O.api("GET", "/api/v1/device-faults/" + id).then(function (f) {
      var box = document.createElement("div");
      box.style.display = "grid";
      box.style.gap = "10px";
      var form = O.form([
        { name: "result", label: t("device.fault.result"), type: "textarea", rows: 3, req: true, max: 2000 },
        { name: "cost", label: t("device.fault.cost"), max: 13, hint: t("device.rep.cost_hint") }
      ], {});
      box.appendChild(form.el);
      var rows = [];
      var parts = document.createElement("div");
      function drawParts() {
        parts.innerHTML = '<label style="font-size:13px;color:var(--text-2)">' + O.esc(t("device.rep.parts")) + '</label><div class="rfrows">' + rows.map(function (r, i) {
          return '<div class="rfrow" data-i="' + i + '"><select class="inp" data-k="component_id">' + f.parts.map(function (p) {
            return '<option value="' + p.id + '"' + (String(p.id) === String(r.component_id) ? " selected" : "") + ">" + O.esc(D.ctype(p.ctype) + " · " + (p.model || p.serial_no)) + "</option>"; }).join("") + "</select>" +
            '<input class="inp" data-k="model" maxlength="150" placeholder="' + O.esc(t("device.part.new_model")) + '" value="' + O.esc(r.model) + '">' +
            '<input class="inp" data-k="serial_no" maxlength="100" placeholder="' + O.esc(t("device.part.new_serial")) + '" value="' + O.esc(r.serial_no) + '">' +
            '<button type="button" class="ib danger" data-del="' + i + '">' + O.icon("trash") + "</button></div>";
        }).join("") + "</div>" + (f.parts.length ? '<button type="button" class="btn sm" data-addp="1" style="margin-top:6px">' + O.icon("plus", 14) + " " + O.esc(t("device.rep.add_part")) + "</button>"
          : '<p class="muted" style="font-size:12.5px;margin:4px 0 0">' + O.esc(t("device.rep.no_parts")) + "</p>") + '<span class="em" id="fp_err" style="color:var(--bad);font-size:12px"></span>';
      }
      parts.addEventListener("input", function (e) { var row = e.target.closest(".rfrow"); if (row) rows[+row.getAttribute("data-i")][e.target.getAttribute("data-k")] = e.target.value; });
      parts.addEventListener("change", function (e) { var row = e.target.closest(".rfrow"); if (row) rows[+row.getAttribute("data-i")][e.target.getAttribute("data-k")] = e.target.value; });
      parts.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("button") : null;
        if (!b) return;
        if (b.hasAttribute("data-addp")) { rows.push({ component_id: f.parts[0].id, model: "", serial_no: "" }); drawParts(); }
        if (b.hasAttribute("data-del")) { rows.splice(+b.getAttribute("data-del"), 1); drawParts(); }
      });
      drawParts();
      box.appendChild(parts);
      O.modal({ title: t("device.rep.finish_title", { device: f.asset_no + " " + f.device }), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("device.rep.finish"), cls: "pri", fn: function () {
        var v = form.values();
        v.parts = rows;
        return O.submit(form, O.api("POST", "/api/v1/device-faults/" + f.id + "/finish", v)).then(function () { O.status(t("device.rep.finished")); load(); }, function (e) {
          if (e && e.fields && e.fields.parts) box.querySelector("#fp_err").textContent = e.fields.parts;
          return Promise.reject(e);
        });
      } }] });
    }, O.failed);
  }

  function cancel(f) {
    var form = O.form([{ name: "reason", label: t("device.fault.cancel_reason"), type: "textarea", rows: 2, req: true, max: 200 }], {});
    O.modal({ title: t("device.rep.cancel"), width: 520, body: form.el, buttons: [{ t: t("common.cancel") }, { t: t("device.rep.cancel"), cls: "danger fill", fn: function () {
      return O.submit(form, O.api("POST", "/api/v1/device-faults/" + f.id + "/cancel", form.values())).then(function () { O.status(t("device.rep.cancelled")); load(); });
    } }] });
  }

  D.meta().then(function (m) {
    M = m;
    shell();
    render();
    if (focusId) { show(focusId); O.focusBar(t("device.rep.located")); focusId = 0; }
  }, O.failed);
})();
