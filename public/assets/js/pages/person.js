/*
 * Staff record drawer (phase 2), shared by the staff list, the own-profile page and the family page.
 * OMPS.person.open(id, {self, tab, onSaved})
 *   view mode first; "Edit" when the viewer may edit (administrator of the record, or the person themself).
 *   For one's own record each field follows its policy: locked, direct, or "needs review".
 */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var META = null;
  var ENTITIES = ["education", "work", "title", "achievement", "family"];
  var DATE_FIELDS = { birth_date: 1, hire_date: 1, obtained_date: 1 };

  function meta() {
    if (META) return Promise.resolve(META);
    return O.api("GET", "/api/v1/personnel/meta").then(function (m) { META = m; return m; });
  }

  function dictText(dict, v) {
    if (v === "" || v === null || v === undefined) return "";
    return t("dict." + dict + "." + v);
  }
  function dictOpts(dict) { return (META.dicts[dict] || []).map(function (c) { return [c, dictText(dict, c)]; }); }
  function fieldType(k) {
    if (DATE_FIELDS[k]) return "date";
    if (/_ym$/.test(k)) return "ym";
    return "text";
  }

  function open(id, opts) {
    opts = opts || {};
    return meta().then(function () {
      var path = opts.self ? "/api/v1/me/profile" : "/api/v1/personnel/" + id;
      return O.api("GET", path).then(function (data) { render(data, opts, !!opts.edit && (opts.self ? data.caps.self_edit : data.caps.admin_edit), opts.tab); });
    }, O.failed).then(null, O.failed);
  }

  function reload(data, opts, edit, tab) {
    var path = opts.self ? "/api/v1/me/profile" : "/api/v1/personnel/" + data.profile.id;
    O.api("GET", path).then(function (d2) { render(d2, opts, edit, tab); }, O.failed);
  }

  function render(data, opts, edit, startTab) {
    var p = data.profile, caps = data.caps;
    var selfEdit = !!opts.self;
    var canEdit = selfEdit ? caps.self_edit : caps.admin_edit;
    var policy = function (k) { return selfEdit && !caps.review_direct ? META.profile[k].policy : (selfEdit && META.profile[k].policy === "forbid" ? "forbid" : "direct"); };
    var tabs = [];
    var forms = {};

    /* ----- basic ----- */
    var basic = document.createElement("div");
    if (data.pending && data.pending.length) {
      basic.insertAdjacentHTML("beforeend", '<div class="note warn" style="margin-bottom:12px">' + O.esc(t("person.pending_note", { n: data.pending.length })) + "</div>");
    }
    var photoRel = (data.files || []).filter(function (f) { return f.group === "photo" && f.id === p.photo_file_id; })[0];
    var photo = document.createElement("div");
    photo.className = "imgup";
    photo.style.marginBottom = "12px";
    photo.innerHTML = '<div class="thumb">' + (photoRel ? '<img alt="" src="' + O.esc(O.url("/api/v1/files/" + photoRel.relation_id, { inline: 1 })) + '">' : O.esc(t("person.no_photo"))) + "</div>" +
      ((canEdit || caps.own) ? '<div><button type="button" class="btn sm" data-photo="1">' + O.icon("upload", 14) + " " + O.esc(t("person.photo_upload")) + '</button><div class="hint muted" style="font-size:12px;margin-top:4px">' + O.esc(t("person.photo_hint")) + "</div></div>" : "");
    basic.appendChild(photo);
    photo.addEventListener("click", function (e) {
      if (!(e.target.closest && e.target.closest("[data-photo]"))) return;
      O.pickFiles({ accept: "image/*" }, function (files) {
        O.upload(opts.self ? "/api/v1/me/profile/files" : "/api/v1/personnel/" + p.id + "/files", files, { group: "photo" }).then(function () {
          O.status(t("person.photo_saved"));
          reload(data, opts, edit, 0);
          if (opts.onSaved) opts.onSaved();
        }, O.failed);
      });
    });

    var keys = ["name", "gender", "birth_date", "ethnicity", "id_type", "id_number", "phone", "email", "address", "hukou_address", "position", "hire_date", "remark"];
    if (!edit) {
      var rows = [[t("staff.field.staff_no"), '<span class="mono">' + O.esc(p.staff_no) + "</span>"], [t("staff.field.dept"), O.esc(p.unassigned ? t("staff.unassigned") : (p.dept || "—"))],
        [t("staff.field.status"), O.esc(t("staff.status." + p.status))]];
      keys.forEach(function (k) {
        var m = META.profile[k], v = p[k];
        var html;
        if (k === "id_number") {
          html = '<span class="mono" id="pv_id">' + O.esc(p.id_number_mask || "—") + "</span>" + (p.id_number_mask && caps.reveal ? ' <button type="button" class="ib mini" data-reveal="1" title="' + O.esc(t("person.reveal")) + '">' + O.icon("eye") + "</button>" : "");
        } else {
          html = O.esc(m.dict ? dictText(m.dict, v) : v) || "—";
        }
        rows.push([t(m.label), html]);
      });
      var tbl = document.createElement("div");
      tbl.className = "fgrid";
      tbl.innerHTML = rows.map(function (r) { return '<div class="fld"><label>' + O.esc(r[0]) + '</label><div class="ro">' + r[1] + "</div></div>"; }).join("") +
        (data.custom && data.custom.defs.length ? '<div class="gtitle">' + O.esc(t("cf.group")) + "</div>" + O.cf.rows(data.custom.defs, data.custom.values).map(function (r) {
          return '<div class="fld' + (r[2] ? " span2" : "") + '"><label>' + O.esc(r[0]) + '</label><div class="ro">' + r[1] + "</div></div>"; }).join("") : "");
      basic.appendChild(tbl);
      tbl.addEventListener("click", function (e) {
        if (!(e.target.closest && e.target.closest("[data-reveal]"))) return;
        O.confirm({ title: t("person.reveal"), msg: t("person.reveal_confirm", { name: p.name }), ok: t("person.reveal_ok") }).then(function (ok) {
          if (!ok) return;
          O.api("POST", "/api/v1/personnel/" + p.id + "/reveal-id", {}).then(function (r) {
            document.getElementById("pv_id").textContent = r.id_number || "—";
            O.status(t("person.revealed"), "warn");
          }, O.failed);
        });
      });
    } else {
      var fields = [];
      keys.forEach(function (k) {
        var m = META.profile[k], pol = policy(k);
        var f = { name: k, label: t(m.label) + (pol === "review" ? " · " + t("person.needs_review") : ""), readonly: pol === "forbid", req: k === "name" || k === "gender" };
        if (m.dict) { f.type = k === "gender" ? "radio" : "select"; f.opts = dictOpts(m.dict); }
        else if (DATE_FIELDS[k]) f.type = "date";
        if (k === "remark") { f.type = "textarea"; f.max = 2000; }
        if (k === "id_number") { f.placeholder = p.id_number_mask || ""; f.hint = t("person.id_keep_hint"); f.autocomplete = "off"; }
        if (pol === "forbid") {
          f = { name: k, label: t(m.label) + " · " + t("person.locked"), type: "static", html: O.esc((m.dict ? dictText(m.dict, p[k]) : p[k]) || "—"), span2: k === "remark" };
        }
        fields.push(f);
      });
      var vals = {};
      keys.forEach(function (k) { vals[k] = k === "id_number" ? "" : p[k]; });
      // custom fields: edited by administrators; read-only in one's own profile
      if (data.custom) {
        fields = fields.concat(O.cf.fields(data.custom.defs, selfEdit));
        var cv = O.cf.values(data.custom.defs, data.custom.values, selfEdit);
        Object.keys(cv).forEach(function (k) { vals[k] = cv[k]; });
      }
      forms.profile = O.form(fields, vals);
      basic.appendChild(forms.profile.el);
    }
    tabs.push({ t: t("staff.group.basic"), el: basic });

    /* ----- repeatable records ----- */
    ENTITIES.forEach(function (e) {
      if (!data[e]) return;
      var em = META.entities[e];
      var cols = Object.keys(em.fields).map(function (k) {
        var fm = em.fields[k];
        return { k: k, t: t(fm.label), type: fm.bool ? "bool" : (fm.dict ? "select" : fieldType(k)), opts: fm.dict ? dictOpts(fm.dict) : null };
      });
      var pol = selfEdit ? (caps.review_direct && em.policy !== "forbid" ? "direct" : em.policy) : (e === "family" && !caps.family_edit ? "forbid" : "direct");
      var box = document.createElement("div");
      if (e === "family") box.insertAdjacentHTML("beforeend", '<div class="note warn" style="margin-bottom:10px">' + O.esc(t(caps.family_full ? "person.family_note_full" : "person.family_note_masked")) + "</div>");
      if (edit && pol === "review") box.insertAdjacentHTML("beforeend", '<div class="note" style="margin-bottom:10px">' + O.esc(t("person.entity_review_note")) + "</div>");
      if (edit && pol === "forbid") box.insertAdjacentHTML("beforeend", '<div class="note" style="margin-bottom:10px">' + O.esc(t("person.entity_locked")) + "</div>");
      var rep = O.repeat({ cols: cols, rows: data[e], readonly: !edit || pol === "forbid" });
      box.appendChild(rep.el);
      if (edit && pol !== "forbid") forms[e] = rep;
      tabs.push({ key: e, t: t(em.label), el: box });
    });

    /* ----- attachments ----- */
    var filesBox = document.createElement("div");
    var groups = ["education", "title", "achievement", "other", "photo"];
    var list = (data.files || []);
    filesBox.innerHTML = (canEdit || caps.own ? '<div class="bar" style="margin-bottom:10px"><select id="pf_group">' + groups.filter(function (g) { return g !== "photo"; }).map(function (g) {
      return '<option value="' + g + '">' + O.esc(t("file.group." + g)) + "</option>"; }).join("") + '</select><button type="button" class="btn pri" data-up="1">' + O.icon("upload", 14) + " " + O.esc(t("file.choose")) +
      '</button><span class="muted" style="font-size:12px">' + O.esc(t("file.limit_hint", { max: META.maxUploadMb })) + "</span></div>" : "") +
      (list.length ? groups.map(function (g) {
        var items = list.filter(function (f) { return f.group === g; });
        if (!items.length) return "";
        return '<div class="gtitle" style="margin:10px 0 4px">' + O.esc(t("file.group." + g)) + '</div><ul class="list">' + items.map(function (f) {
          var mine = f.uploaded_by === O.boot.user.id || O.boot.user.scope === "all";
          return '<li><div class="grow"><a class="lk" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id)) + '">' + O.esc(f.name) + '</a><div class="sub">' + O.esc(O.fileSize(f.size) + " · " + f.uploader + " · " + f.at) + "</div></div>" +
            (f.image ? '<a class="ib" target="_blank" rel="noopener" href="' + O.esc(O.url("/api/v1/files/" + f.relation_id, { inline: 1 })) + '" title="' + O.esc(t("file.preview")) + '">' + O.icon("eye") + "</a>" : "") +
            (mine ? '<button type="button" class="ib danger" data-delfile="' + f.relation_id + '" title="' + O.esc(t("common.delete")) + '">' + O.icon("trash") + "</button>" : "") + "</li>";
        }).join("") + "</ul>";
      }).join("") : '<p class="muted">' + O.esc(t("file.none")) + "</p>");
    filesBox.addEventListener("click", function (e) {
      var up = e.target.closest ? e.target.closest("[data-up]") : null;
      if (up) {
        var g = document.getElementById("pf_group").value;
        O.pickFiles({ multiple: true }, function (files) {
          O.upload(opts.self ? "/api/v1/me/profile/files" : "/api/v1/personnel/" + p.id + "/files", files, { group: g }).then(function (r) {
            O.status(r.duplicates ? t("file.uploaded_dup", { n: files.length, d: r.duplicates }) : t("file.uploaded", { n: files.length }), r.duplicates ? "warn" : "ok");
            reload(data, opts, edit, tabs.length - 1);
          }, O.failed);
        });
      }
      var del = e.target.closest ? e.target.closest("[data-delfile]") : null;
      if (del) {
        O.confirm({ title: t("common.delete"), msg: t("file.delete_confirm"), ok: t("common.delete"), danger: true }).then(function (ok) {
          if (!ok) return;
          O.api("DELETE", "/api/v1/files/" + del.getAttribute("data-delfile")).then(function () { O.status(t("common.deleted")); reload(data, opts, edit, tabs.length - 1); }, O.failed);
        });
      }
    });
    tabs.push({ t: t("staff.group.files") + (list.length ? " (" + list.length + ")" : ""), el: filesBox });

    /* ----- buttons ----- */
    var buttons = [];
    if (!edit) {
      if (!opts.self && !caps.own) buttons.push({ t: t("person.history"), fn: function () { history(p); return false; } });
      buttons.push({ t: t("person.doc"), fn: function (dr) { regForm(p, opts, dr); return false; } });
      if (canEdit) buttons.push({ t: t("common.edit"), cls: "pri", fn: function () { render(data, opts, true, 0); return false; } });
      buttons.push({ t: t("common.close") });
    } else {
      buttons.push({ t: t("common.cancel"), fn: function () { render(data, opts, false, 0); return false; } });
      buttons.push({ t: selfEdit && !caps.review_direct ? t("person.save_submit") : t("common.save"), cls: "pri", fn: function (dr) { return save(data, opts, forms, dr); } });
    }
    var dr = O.drawer({
      title: p.name, sub: p.staff_no + (p.dept ? " · " + p.dept : "") + (p.position ? " · " + p.position : ""), width: 980, tabs: tabs, buttons: buttons,
      foot: edit && selfEdit && !caps.review_direct ? t("person.self_foot") : ""
    });
    if (typeof startTab === "string") { tabs.forEach(function (tb, i) { if (tb.key === startTab) dr.show(i); }); } else if (startTab) dr.show(startTab);
  }

  /* registration form (Word): one template downloads at once, several open a menu */
  function regForm(p, opts, dr) {
    var path = opts.self ? "/api/v1/me/profile/document" : "/api/v1/personnel/" + p.id + "/document";
    var btn = Array.prototype.filter.call(dr.el.querySelectorAll(".d-f button"), function (x) { return x.textContent === t("person.doc"); })[0];
    O.docChoices("person").then(function (list) {
      if (list.length < 2) { window.location.href = O.url(path); return; }
      O.dropdown(btn, list.map(function (c) { return { t: c.name, fn: function () { window.location.href = O.url(path, { tpl: c.id }); } }; }));
    }, O.failed);
  }

  function save(data, opts, forms, dr) {
    var body = { version: data.profile.version };
    if (forms.profile) {
      body.profile = forms.profile.values();
      var custom = O.cf.collect(body.profile);
      if (!opts.self) body.custom = custom;
    }
    ENTITIES.forEach(function (e) {
      if (forms[e]) body[e] = forms[e].values().map(function (r) {
        var o = {};
        Object.keys(r).forEach(function (k) { o[k] = r[k]; });
        return o;
      });
    });
    var path = opts.self ? "/api/v1/me/profile" : "/api/v1/personnel/" + data.profile.id;
    if (forms.profile) forms.profile.errors(null);
    return O.api("PUT", path, body).then(function (r) {
      if (opts.self) {
        O.status(r.submitted ? t("person.saved_self", { applied: r.applied, submitted: r.submitted }) : t("person.saved_direct", { n: r.applied }), r.submitted ? "warn" : "ok");
      } else {
        O.status(t("common.saved"));
      }
      if (opts.onSaved) opts.onSaved();
      setTimeout(function () { reload(data, opts, false, 0); }, 0);
    }, function (err) {
      if (err && err.fields) {
        if (forms.profile) forms.profile.errors(err.fields);
        var ents = ENTITIES.filter(function (e) { return err.fields[e]; });
        if (ents.length) O.info(t("error.title_refused"), "<p>" + ents.map(function (e) { return O.esc(t(META.entities[e].label) + ": " + err.fields[e]); }).join("<br>") + "</p>");
        O.status(err.message, "err");
        return Promise.reject(null);
      }
      return Promise.reject(err);
    });
  }

  function history(p) {
    O.api("GET", "/api/v1/personnel/" + p.id + "/history").then(function (rows) {
      var html = rows.length ? '<ul class="timeline">' + rows.map(function (r) {
        return "<li><b>" + O.esc(r.time) + "</b> " + O.esc(r.user) + " · " + O.esc(r.action) +
          (r.before || r.after ? '<div class="muted mono" style="font-size:12px;word-break:break-all">' + O.esc(JSON.stringify(r.before || {})) + " → " + O.esc(JSON.stringify(r.after || {})) + "</div>" : "") + "</li>";
      }).join("") + "</ul>" : '<p class="muted">' + O.esc(t("common.empty")) + "</p>";
      O.modal({ title: t("person.history") + " · " + p.name, width: 640, body: html });
    }, O.failed);
  }

  /* What a change request changes, as HTML (labels and dictionary values in the viewer's language). */
  function describe(r) {
    var labelOf, dictOf;
    if (r.entity === "profile") {
      labelOf = function (k) { return META.profile[k] ? t(META.profile[k].label) : k; };
      dictOf = function (k) { return META.profile[k] ? META.profile[k].dict : null; };
    } else {
      var em = META.entities[r.entity] || { fields: {} };
      labelOf = function (k) { return em.fields[k] ? t(em.fields[k].label) : k; };
      dictOf = function (k) { return em.fields[k] ? em.fields[k].dict : null; };
    }
    var val = function (k, v) {
      if (v === null || v === undefined || v === "") return "—";
      if (v === true) return t("common.yes");
      if (v === false) return t("common.no");
      var d = dictOf(k);
      return d ? dictText(d, v) : String(v);
    };
    var head = r.entity === "profile" ? t("staff.group.basic") : t(META.entities[r.entity].label) + " · " + t("review.action." + r.action);
    var before = r.before || {}, after = r.after || {};
    var keys = r.action === "delete" ? Object.keys(before) : Object.keys(after);
    var lines = keys.filter(function (k) {
      if (r.action !== "update") return before[k] !== "" || after[k] !== "";
      return String(before[k] === undefined || before[k] === null ? "" : before[k]) !== String(after[k] === undefined || after[k] === null ? "" : after[k]);
    }).map(function (k) {
      if (r.action === "create") return "<div>" + O.esc(labelOf(k)) + t("common.colon") + '<span class="diff-add">' + O.esc(val(k, after[k])) + "</span></div>";
      if (r.action === "delete") return "<div>" + O.esc(labelOf(k)) + t("common.colon") + '<span class="diff-del">' + O.esc(val(k, before[k])) + "</span></div>";
      return "<div>" + O.esc(labelOf(k)) + t("common.colon") + '<span class="diff-del">' + O.esc(val(k, before[k])) + '</span> → <span class="diff-add">' + O.esc(val(k, after[k])) + "</span></div>";
    });
    return "<b>" + O.esc(head) + '</b><div style="font-size:12.5px;white-space:normal">' + lines.join("") + "</div>";
  }

  O.person = { open: open, meta: meta, describe: describe, dictText: function (d, v) { return META ? dictText(d, v) : v; } };
})();
