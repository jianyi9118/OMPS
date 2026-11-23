/* Devices (phase 6), shared: names and pills, the device form, the detail drawer, the fault report dialog (报修) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var META = null;

  var STATUS_PILL = { normal: "ok", fault: "bad", repairing: "warn", disabled: "", scrapped: "" };
  var FAULT_PILL = { open: "bad", repairing: "warn", fixed: "ok", cancelled: "" };
  function statusPill(s) { return O.pill(t("device.status." + s), STATUS_PILL[s]); }
  function faultPill(s) { return O.pill(t("device.fault_status." + s), FAULT_PILL[s]); }
  function cat(c) { return t("dict.device_category." + c); }
  function ctype(c) { return t("device.ctype." + c); }
  function para(s) { return O.esc(s || "").replace(/\n/g, "<br>"); }

  function meta() {
    if (META) return Promise.resolve(META);
    return O.api("GET", "/api/v1/devices/meta").then(function (m) { META = m; return m; });
  }

  function filesHtml(files) {
    if (!files || !files.length) return "";
    return '<div class="isfiles">' + files.map(function (f) {
      return '<div class="isf"><span class="grow"><a class="lk" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id)) + '">' + O.esc(f.name) + '</a> <span class="muted" style="font-size:12px">' +
        O.esc(O.fileSize(f.size) + " · " + f.uploader) + "</span></span>" + (f.image ? '<a class="ib" target="_blank" rel="noopener" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id, { inline: 1 })) +
        '" title="' + O.esc(t("file.preview")) + '">' + O.icon("eye") + "</a>" : "") + "</div>";
    }).join("") + "</div>";
  }

  /* ---------- device form ---------- */
  function form(d, onSaved) {
    meta().then(function (m) {
      var edit = !!(d && d.id);
      var statuses = m.manual_statuses.slice();
      if (edit && statuses.indexOf(d.run_status) < 0) statuses.unshift(d.run_status);
      var f = O.form([
        { type: "group", label: t("device.group.basic") },
        { name: "asset_no", label: t("device.field.asset_no"), req: true, max: 50 },
        { name: "name", label: t("device.field.name"), req: true, max: 100 },
        { name: "category", label: t("device.field.category"), type: "select", req: true, opts: m.categories.map(function (c) { return [c, cat(c)]; }) },
        { name: "run_status", label: t("device.field.run_status"), type: "select", req: true, readonly: edit && (d.run_status === "fault" || d.run_status === "repairing"),
          opts: statuses.map(function (s) { return [s, t("device.status." + s)]; }), hint: edit && (d.run_status === "fault" || d.run_status === "repairing") ? t("device.status_hint") : "" },
        { name: "brand", label: t("device.field.brand"), max: 50 },
        { name: "model", label: t("device.field.model"), max: 100 },
        { name: "serial_no", label: t("device.field.serial_no"), max: 100 },
        { name: "location", label: t("device.field.location"), max: 100 },
        { type: "group", label: t("device.group.use") },
        { name: "dept_id", label: t("device.field.dept"), type: "select", int: true, opts: O.deptOpts(m.depts) },
        { name: "holder_person_id", label: t("device.field.holder"), type: "select", int: true, opts: m.people.map(function (p) { return [p.id, p.name + (p.dept ? " · " + p.dept : "")]; }) },
        { type: "group", label: t("device.group.purchase") },
        { name: "purchase_date", label: t("device.field.purchase_date"), type: "date" },
        { name: "received_date", label: t("device.field.received_date"), type: "date" },
        { name: "price", label: t("device.field.price"), max: 13 },
        { name: "warranty_until", label: t("device.field.warranty_until"), type: "date" },
        { name: "remark", label: t("device.field.remark"), type: "textarea", rows: 2, max: 500 }
      ].concat(O.cf.fields(m.custom || [], false)), (function () {
        var v = d ? JSON.parse(JSON.stringify(d)) : { category: "pc", run_status: "normal" };
        var cv = O.cf.values(m.custom || [], d && d.custom ? d.custom.values : {}, false);
        Object.keys(cv).forEach(function (k) { v[k] = cv[k]; });
        return v;
      })());
      var box = document.createElement("div");
      box.appendChild(f.el);
      if (edit) box.insertAdjacentHTML("beforeend", '<p class="muted" style="font-size:12.5px">' + O.esc(t("device.holder_hint")) + "</p>");
      O.modal({ title: t(edit ? "device.edit" : "device.add"), width: 760, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
        var v = f.values();
        v.custom = O.cf.collect(v);
        if (edit) v.version = d.version;
        return O.submit(f, edit ? O.api("PUT", "/api/v1/devices/" + d.id, v) : O.api("POST", "/api/v1/devices", v)).then(function (r) {
          O.status(t("common.saved"));
          if (onSaved) onSaved(edit ? d.id : r.id);
        });
      } }] });
    }, O.failed);
  }

  /* ---------- detail drawer ---------- */
  function detail(id, opts) {
    opts = opts || {};
    return Promise.all([meta(), O.api("GET", "/api/v1/devices/" + id)]).then(function (all) {
      var m = all[0], d = all[1], c = d.caps;
      var reload = function (tab) { O.closeDrawer(); detail(id, { tab: tab, onChange: opts.onChange }); if (opts.onChange) opts.onChange(); };
      var tabs = [];

      // basic
      var b = document.createElement("div");
      var rows = [[t("device.field.asset_no"), O.esc(d.asset_no)], [t("device.field.name"), O.esc(d.name)], [t("device.field.category"), O.esc(cat(d.category))],
        [t("device.field.run_status"), statusPill(d.run_status)], [t("device.field.brand"), O.esc(d.brand || "—")], [t("device.field.model"), O.esc(d.model || "—")],
        [t("device.field.serial_no"), O.esc(d.serial_no || "—")], [t("device.field.location"), O.esc(d.location || "—")], [t("device.field.dept"), O.esc(d.dept || "—")],
        [t("device.field.holder"), O.esc(d.holder || "—")], [t("device.field.purchase_date"), O.esc(d.purchase_date || "—")], [t("device.field.received_date"), O.esc(d.received_date || "—")],
        [t("device.field.price"), O.esc(d.price || "—")], [t("device.field.warranty_until"), O.esc(d.warranty_until || "—")]];
      if (d.custom) O.cf.rows(d.custom.defs, d.custom.values).forEach(function (r) { rows.push([r[0], r[1]]); });
      b.innerHTML = '<div class="fgrid">' + rows.map(function (r) { return '<div class="fld"><label>' + O.esc(r[0]) + '</label><div class="ro">' + r[1] + "</div></div>"; }).join("") +
        (d.remark ? '<div class="fld span2"><label>' + O.esc(t("device.field.remark")) + '</label><div class="ro">' + para(d.remark) + "</div></div>" : "") + "</div>";
      tabs.push({ t: t("device.tab.basic"), el: b });

      // parts
      var p = document.createElement("div");
      var inUse = d.components.filter(function (x) { return !x.removed_on; });
      var gone = d.components.filter(function (x) { return x.removed_on; });
      p.appendChild(O.table({
        cols: [{ t: t("device.part.ctype"), f: function (x) { return O.esc(ctype(x.ctype)); } }, { t: t("device.part.model"), cls: "wrap", f: function (x) { return O.esc(x.model || "—"); } },
          { t: t("device.part.serial_no"), f: function (x) { return '<span class="mono">' + O.esc(x.serial_no || "—") + "</span>"; } }, { t: t("device.part.spec"), f: function (x) { return O.esc(x.spec); } },
          { t: t("device.part.installed_on"), f: function (x) { return '<span class="mono">' + O.esc(x.installed_on) + "</span>" + (x.source === "collector" ? ' <span class="muted" style="font-size:11px">' + O.esc(t("device.part.auto")) + "</span>" : ""); } }],
        rows: inUse, empty: t("device.part.none"),
        acts: c.edit ? function () { return [{ icon: "transfer", tip: t("device.part.replace"), fn: function (x) { partForm(d, x, reload); } }, { icon: "trash", tip: t("device.part.remove"), danger: true, fn: function (x) { removePart(d, x, reload); } }]; } : null
      }));
      if (c.edit) p.insertAdjacentHTML("beforeend", '<div style="margin:8px 0"><button type="button" class="btn sm" data-add-part="1">' + O.icon("plus", 14) + " " + O.esc(t("device.part.add")) + "</button></div>");
      p.insertAdjacentHTML("beforeend", '<h4 style="margin:14px 0 6px">' + O.esc(t("device.part.history")) + "</h4>" + (d.history.length ? '<ul class="list" style="font-size:12.5px">' + d.history.map(function (h) {
        return '<li><span class="mono muted">' + O.esc(h.at.slice(0, 16)) + '</span><span class="grow">' + O.esc(t("device.hist." + h.action, { type: ctype(h.ctype), part: (h.model + " " + h.serial_no).trim(), old: (h.old_model + " " + h.old_serial).trim() })) +
          (h.repair_id ? " · " + O.esc(t("device.hist.by_repair")) : "") + (h.anomaly_id ? " · " + O.esc(t("device.hist.by_anomaly")) : "") + (h.note ? " · " + O.esc(h.note) : "") + "</span><span class=\"muted\">" + O.esc(h.by) + "</span></li>";
      }).join("") + "</ul>" : '<p class="muted">' + O.esc(t("device.part.no_history")) + "</p>") +
        (gone.length ? '<p class="muted" style="font-size:12px">' + O.esc(t("device.part.removed_n", { n: gone.length })) + "</p>" : ""));
      p.addEventListener("click", function (e) { if (e.target.closest && e.target.closest("[data-add-part]")) partForm(d, null, reload); });
      tabs.push({ t: t("device.tab.parts") + " (" + inUse.length + ")", el: p });

      // licences
      var l = document.createElement("div");
      l.appendChild(O.table({
        cols: [{ t: t("device.lic.name"), f: function (x) { return O.esc(x.name); } }, { t: t("device.lic.license_no"), cls: "wrap", f: function (x) { return '<span class="mono">' + O.esc(x.license_no || "—") + "</span>"; } },
          { t: t("device.lic.seats"), cls: "num", f: function (x) { return x.seats === null ? "—" : String(x.seats); } },
          { t: t("device.lic.expires_on"), f: function (x) { return x.expires_on ? '<span class="mono">' + O.esc(x.expires_on) + "</span>" + (x.expires_on < O.today() ? " " + O.pill(t("device.lic.expired"), "bad") : "") : '<span class="muted">' + O.esc(t("device.lic.perpetual")) + "</span>"; } },
          { t: t("device.field.remark"), cls: "wrap", f: function (x) { return O.esc(x.remark); } }],
        rows: d.licenses, empty: t("device.lic.none"),
        acts: c.edit ? function () { return [{ icon: "edit", tip: t("common.edit"), fn: function (x) { licenseForm(d, x, reload); } }, { icon: "trash", tip: t("common.delete"), danger: true, fn: function (x) { removeLicense(x, reload); } }]; } : null
      }));
      if (c.edit) {
        l.insertAdjacentHTML("beforeend", '<div style="margin:8px 0"><button type="button" class="btn sm" data-add-lic="1">' + O.icon("plus", 14) + " " + O.esc(t("device.lic.add")) + "</button></div>" +
          '<p class="muted" style="font-size:12.5px">' + O.esc(t("device.lic.hint")) + "</p>");
        l.addEventListener("click", function (e) { if (e.target.closest && e.target.closest("[data-add-lic]")) licenseForm(d, null, reload); });
      }
      tabs.push({ t: t("device.tab.licenses") + " (" + d.licenses.length + ")", el: l });

      // holders
      var h = document.createElement("div");
      h.appendChild(O.table({ cols: [{ t: t("device.field.holder"), f: function (x) { return O.esc(x.person || "—"); } }, { t: t("device.field.dept"), f: function (x) { return O.esc(x.dept || "—"); } },
        { t: t("device.holder.period"), f: function (x) { return '<span class="mono">' + O.esc(x.start + " – " + (x.end || t("device.holder.now"))) + "</span>"; } }, { t: t("device.field.note"), cls: "wrap", f: function (x) { return O.esc(x.note); } }],
        rows: d.holders, empty: t("device.holder.none") }));
      if (c.edit) {
        h.insertAdjacentHTML("beforeend", '<div style="margin:8px 0"><button type="button" class="btn sm" data-holder="1">' + O.icon("transfer", 14) + " " + O.esc(t("device.holder.change")) + "</button></div>");
        h.addEventListener("click", function (e) { if (e.target.closest && e.target.closest("[data-holder]")) holderForm(d, m, reload); });
      }
      tabs.push({ t: t("device.tab.holders"), el: h });

      // faults
      var fl = document.createElement("div");
      fl.appendChild(O.table({ cols: [{ t: t("device.fault.reported_at"), f: function (x) { return '<span class="mono">' + O.esc(x.reported_at.slice(0, 16)) + "</span>"; } },
        { t: t("device.fault.reporter"), f: function (x) { return O.esc(x.reporter); } }, { t: t("device.fault.description"), cls: "wrap", f: function (x) { return O.esc(x.description); } },
        { t: t("device.fault.status"), f: function (x) { return faultPill(x.status); } },
        { t: t("device.fault.result"), cls: "wrap", f: function (x) { return x.repair ? O.esc(x.repair.repairer + (x.repair.result ? " · " + x.repair.result : "") + (x.repair.cost ? " · " + t("device.fault.cost_is", { cost: x.repair.cost }) : "")) : ""; } }],
        rows: d.faults, empty: t("device.fault.none") }));
      tabs.push({ t: t("device.tab.faults") + " (" + d.faults.length + ")", el: fl });

      // files
      var fe = document.createElement("div");
      fe.innerHTML = (filesHtml(d.files) || '<p class="muted">' + O.esc(t("file.none")) + "</p>") +
        (c.edit ? '<div style="margin-top:8px"><button type="button" class="btn sm" data-up="1">' + O.icon("upload", 14) + " " + O.esc(t("device.upload")) + "</button></div>" : "");
      fe.addEventListener("click", function (e) {
        if (!(e.target.closest && e.target.closest("[data-up]"))) return;
        O.pickFiles({ multiple: true }, function (files) { O.upload("/api/v1/devices/" + d.id + "/files", files).then(function () { O.status(t("device.uploaded")); reload(5); }, O.failed); });
      });
      tabs.push({ t: t("device.tab.files") + " (" + d.files.length + ")", el: fe });

      // collector
      var co = document.createElement("div");
      var active = d.collectors.filter(function (x) { return x.status === "active"; })[0];
      var pending = d.collectors.filter(function (x) { return x.status === "pending"; })[0];
      co.innerHTML = '<div class="note">' + O.esc(t("device.coll.scope_is", { items: d.scope_items.map(function (k) { return t("device.item." + k); }).join(t("common.list_sep")) || t("device.coll.scope_none") })) + " " + O.esc(t("device.coll.boundary")) + "</div>" +
        (active ? '<div class="fgrid"><div class="fld"><label>' + O.esc(t("device.coll.computer")) + '</label><div class="ro">' + O.esc(active.computer_name || "—") + '</div></div><div class="fld"><label>' +
          O.esc(t("device.coll.os")) + '</label><div class="ro">' + O.esc(active.os || "—") + '</div></div><div class="fld"><label>' + O.esc(t("device.coll.registered")) + '</label><div class="ro mono">' +
          O.esc(active.registered_at.slice(0, 16)) + '</div></div><div class="fld"><label>' + O.esc(t("device.coll.last_seen")) + '</label><div class="ro mono">' + O.esc(active.last_seen.slice(0, 16) + " · " + active.last_ip) + "</div></div></div>"
          : '<p class="muted">' + O.esc(t("device.coll.not_installed")) + "</p>") +
        (pending ? '<p class="muted">' + O.esc(t("device.coll.pending", { at: pending.enroll_expires.slice(0, 16) })) + "</p>" : "") +
        (c.edit ? '<div style="margin-top:8px"><button type="button" class="btn sm" data-code="1">' + O.icon("key", 14) + " " + O.esc(t(active ? "device.coll.new_code_again" : "device.coll.new_code")) + "</button></div>" : "");
      co.addEventListener("click", function (e) { if (e.target.closest && e.target.closest("[data-code]")) enrollCode(d); });
      tabs.push({ t: t("device.tab.collector"), el: co });

      var btns = [];
      if (c.edit) btns.push({ t: t("common.edit"), fn: function () { form(d, function () { reload(0); }); return false; } });
      btns.push({ t: t("common.close") });
      var dr = O.drawer({ title: d.asset_no + " " + d.name, sub: cat(d.category), width: 920, tabs: tabs, buttons: btns });
      if (opts.tab) dr.show(opts.tab);
      return d;
    }, O.failed);
  }

  function partForm(d, part, done) {
    var f = O.form([
      part ? { name: "ctype_ro", label: t("device.part.ctype"), type: "static", value: ctype(part.ctype) + (part.model ? " · " + part.model : "") }
        : { name: "ctype", label: t("device.part.ctype"), type: "select", req: true, opts: META.ctypes.map(function (x) { return [x, ctype(x)]; }) },
      { name: "model", label: t(part ? "device.part.new_model" : "device.part.model"), max: 150 },
      { name: "serial_no", label: t(part ? "device.part.new_serial" : "device.part.serial_no"), max: 100 },
      { name: "spec", label: t("device.part.spec"), max: 100, hint: t("device.part.spec_hint") },
      { name: "date", label: t("device.field.date"), type: "date", value: O.today() },
      { name: "note", label: t("device.field.note"), max: 200 }
    ], {});
    O.modal({ title: t(part ? "device.part.replace" : "device.part.add"), width: 620, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var url = part ? "/api/v1/devices/" + d.id + "/parts/" + part.id + "/replace" : "/api/v1/devices/" + d.id + "/parts";
      return O.submit(f, O.api("POST", url, f.values())).then(function () { O.status(t("common.saved")); done(1); });
    } }] });
  }

  function removePart(d, part, done) {
    O.confirm({ title: t("device.part.remove"), msg: t("device.part.remove_confirm", { part: ctype(part.ctype) + " " + (part.model || part.serial_no) }), danger: true, ok: t("device.part.remove") }).then(function (ok) {
      if (ok) O.api("POST", "/api/v1/devices/" + d.id + "/parts/" + part.id + "/remove", { date: O.today() }).then(function () { done(1); }, O.failed);
    });
  }

  function licenseForm(d, lic, done) {
    var f = O.form([
      { name: "name", label: t("device.lic.name"), req: true, max: 100 },
      { name: "license_no", label: t("device.lic.license_no"), max: 200 },
      { name: "seats", label: t("device.lic.seats"), type: "number", int: true },
      { name: "expires_on", label: t("device.lic.expires_on"), type: "date", hint: t("device.lic.expires_hint") },
      { name: "remark", label: t("device.field.remark"), max: 200, span2: true }
    ], lic || {});
    O.modal({ title: t(lic ? "device.lic.edit" : "device.lic.add"), width: 620, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      var p = lic ? O.api("PUT", "/api/v1/device-licenses/" + lic.id, f.values()) : O.api("POST", "/api/v1/devices/" + d.id + "/licenses", f.values());
      return O.submit(f, p).then(function () { O.status(t("common.saved")); done(2); });
    } }] });
  }

  function removeLicense(lic, done) {
    O.confirm({ title: t("device.lic.delete"), msg: t("device.lic.delete_confirm", { name: lic.name }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/device-licenses/" + lic.id).then(function () { done(2); }, O.failed);
    });
  }

  function holderForm(d, m, done) {
    var f = O.form([
      { name: "person_id", label: t("device.holder.to"), type: "select", int: true, empty: t("device.holder.nobody"), opts: m.people.map(function (p) { return [p.id, p.name + (p.dept ? " · " + p.dept : "")]; }) },
      { name: "dept_id", label: t("device.field.dept"), type: "select", int: true, opts: O.deptOpts(m.depts), hint: t("device.holder.dept_hint") },
      { name: "date", label: t("device.field.date"), type: "date", req: true, value: O.today() },
      { name: "note", label: t("device.field.note"), max: 200, placeholder: t("device.holder.note_ph") }
    ], { dept_id: d.dept_id });
    O.modal({ title: t("device.holder.change"), width: 560, body: f.el, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
      return O.submit(f, O.api("POST", "/api/v1/devices/" + d.id + "/holder", f.values())).then(function () { O.status(t("common.saved")); done(3); });
    } }] });
  }

  function enrollCode(d) {
    O.api("POST", "/api/v1/devices/" + d.id + "/collector-code", {}).then(function (r) {
      O.modal({ title: t("device.coll.code_title"), width: 600, body: '<div style="display:grid;gap:10px"><p>' + O.esc(t("device.coll.code_intro", { device: d.asset_no + " " + d.name })) + "</p>" +
        '<div style="font-size:26px;letter-spacing:3px;text-align:center" class="mono"><b>' + O.esc(r.code) + "</b></div>" +
        '<div class="fgrid"><div class="fld"><label>' + O.esc(t("device.coll.server")) + '</label><div class="ro mono">' + O.esc(r.server) + '</div></div><div class="fld"><label>' +
        O.esc(t("device.coll.expires")) + '</label><div class="ro mono">' + O.esc(r.expires) + "</div></div></div>" +
        '<ol style="margin:0;padding-left:18px;font-size:13px;display:grid;gap:4px"><li>' + O.esc(t("device.coll.step1")) + "</li><li>" + O.esc(t("device.coll.step2")) + "</li><li>" + O.esc(t("device.coll.step3")) + "</li></ol>" +
        '<p class="muted" style="font-size:12.5px;margin:0">' + O.esc(t("device.coll.code_once")) + "</p></div>", buttons: [{ t: t("common.close"), cls: "pri" }] });
    }, O.failed);
  }

  /* ---------- fault report (报修), everyone ---------- */
  function reportFault() {
    var pick = null, files = [];
    O.api("GET", "/api/v1/device-faults/options").then(function (res) {
      var box = document.createElement("div");
      box.style.display = "grid";
      box.style.gap = "12px";
      function devList(list) {
        return list.length ? list.map(function (x) {
          return '<label class="rmopt"><input type="radio" name="rf_dev" value="' + x.id + '"' + (pick === x.id ? " checked" : "") + '><span><b>' + O.esc(x.asset_no + " " + x.name) + "</b>" +
            '<span class="muted">' + O.esc((x.location || "") + (x.mine ? (x.location ? " · " : "") + t("device.report.mine") : "")) + "</span></span></label>";
        }).join("") : '<p class="muted" style="margin:0">' + O.esc(t("device.report.none_found")) + "</p>";
      }
      box.innerHTML = '<div class="fld"><label><b class="req">*</b>' + O.esc(t("device.report.which")) + '</label><input class="inp" type="search" id="rf_kw" placeholder="' + O.esc(t("device.report.search")) + '">' +
        '<div class="rmopts" id="rf_list" style="max-height:220px;overflow:auto;margin-top:6px">' + devList(res.devices) + '</div><span class="em" id="rf_derr" style="color:var(--bad);font-size:12px"></span></div>' +
        '<div class="fld"><label><b class="req">*</b>' + O.esc(t("device.fault.description")) + '</label><textarea class="inp" id="rf_desc" rows="4" maxlength="2000" placeholder="' + O.esc(t("device.report.desc_ph")) + '"></textarea>' +
        '<span class="em" id="rf_err" style="color:var(--bad);font-size:12px"></span></div>' +
        '<div class="isrow"><button type="button" class="btn sm" id="rf_pick">' + O.icon("upload", 14) + " " + O.esc(t("device.report.photos")) + '</button><span class="muted" id="rf_files" style="font-size:12.5px"></span></div>' +
        (res.mine.length ? '<details class="more"><summary>' + O.esc(t("device.report.my_reports", { n: res.mine.length })) + '</summary><ul class="list" style="font-size:12.5px">' + res.mine.map(function (x) {
          return '<li><span class="grow"><b>' + O.esc(x.asset_no + " " + x.device) + "</b> " + faultPill(x.status) + '<div class="sub">' + O.esc(x.reported_at.slice(0, 16) + " · " + x.description.slice(0, 60)) +
            (x.repair && x.repair.result ? " · " + O.esc(t("device.report.result_is", { result: x.repair.result })) : "") + (x.cancel_reason ? " · " + O.esc(x.cancel_reason) : "") + "</div></span></li>";
        }).join("") + "</ul></details>" : "");
      box.querySelector("#rf_list").addEventListener("change", function (e) { if (e.target.name === "rf_dev") pick = +e.target.value; });
      box.querySelector("#rf_kw").addEventListener("input", O.debounce(function (e) {
        O.api("GET", "/api/v1/device-faults/options", null, { kw: e.target.value.trim() }).then(function (r) { box.querySelector("#rf_list").innerHTML = devList(r.devices); }, O.failed);
      }, 350));
      box.querySelector("#rf_pick").addEventListener("click", function () {
        O.pickFiles({ multiple: true, accept: "image/*,.pdf" }, function (fl) { files = files.concat(fl); box.querySelector("#rf_files").textContent = files.map(function (x) { return x.name; }).join(t("common.list_sep")); });
      });
      O.modal({ title: t("device.report.title"), width: 640, sticky: true, body: box, buttons: [{ t: t("common.cancel") }, { t: t("device.report.send"), cls: "pri", fn: function () {
        var desc = box.querySelector("#rf_desc").value.trim();
        box.querySelector("#rf_derr").textContent = pick ? "" : t("device.report.pick_first");
        box.querySelector("#rf_err").textContent = desc ? "" : t("device.report.desc_first");
        if (!pick || !desc) return false;
        return O.upload("/api/v1/device-faults/report", files, { device_id: pick, description: desc }).then(function () { O.status(t("device.report.sent")); });
      } }] });
    }, O.failed);
  }
  O.on("reportFault", function () { reportFault(); });

  O.device = { meta: meta, statusPill: statusPill, faultPill: faultPill, cat: cat, ctype: ctype, para: para, filesHtml: filesHtml, form: form, detail: detail, reportFault: reportFault };

  if (O.qs("repairs") === "1" && document.querySelector('[data-a="reportFault"]')) reportFault();
})();
