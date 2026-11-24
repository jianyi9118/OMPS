/* Family records (highly sensitive; every view is audited) */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var state = { page: 1 };

  view.innerHTML = '<div class="note warn">' + O.esc(t("family.page_note")) + '</div><div class="bar"><span class="sp"></span></div><div class="card" id="fm_card"></div>';
  // no meaningful date here: keyword only (phone and address are encrypted and not searchable)
  var bar = view.querySelector(".bar");
  bar.insertBefore(O.filters({ state: state, onChange: load, placeholder: t("family.search"),
    fields: [["person", t("family.col.person")], ["staff_no", t("staff.field.staff_no")], ["dept", t("staff.field.dept")], ["name", t("family.field.name")],
      ["employer", t("family.field.employer")], ["position", t("family.field.position")]] }), bar.querySelector(".sp"));

  function load() {
    var q = O.filterQuery(state);
    q.page = state.page;
    O.api("GET", "/api/v1/family", null, q).then(function (res) {
      var card = document.getElementById("fm_card");
      card.innerHTML = "";
      card.appendChild(O.table({
        cols: [
          { t: t("family.col.person"), f: function (r) { return '<button type="button" class="lk" data-p="' + r.person_id + '">' + O.esc(r.person) + '</button><div class="muted mono" style="font-size:12px">' + O.esc(r.staff_no) + "</div>"; } },
          { t: t("staff.field.dept"), f: function (r) { return O.esc(r.dept || "—"); } },
          { t: t("family.field.name"), f: function (r) { return O.esc(r.name); } },
          { t: t("family.field.relation"), f: function (r) { return O.esc(O.person.dictText("relation", r.relation)); } },
          { t: t("family.field.gender"), f: function (r) { return O.esc(r.gender ? O.person.dictText("gender", r.gender) : "—"); } },
          { t: t("family.field.birth_date"), f: function (r) { return '<span class="mono">' + O.esc(r.birth_date || "—") + "</span>"; } },
          { t: t("family.field.employer"), f: function (r) { return O.esc(r.employer || "—"); } },
          { t: t("family.field.phone"), f: function (r) { return '<span class="mono">' + O.esc(r.phone || "—") + "</span>"; } },
          { t: t("family.field.address"), cls: "wrap", f: function (r) { return O.esc(r.address || "—"); } }
        ],
        rows: res.rows, empty: t("family.empty")
      }));
      card.appendChild(O.pager(res, function (p) { state.page = p; load(); }));
      if (!res.full) card.insertAdjacentHTML("beforeend", '<div class="iblegend">' + O.esc(t("family.masked_note")) + "</div>");
      card.onclick = function (e) {
        var b = e.target.closest ? e.target.closest("[data-p]") : null;
        if (b) O.person.open(+b.getAttribute("data-p"), { tab: "family", onSaved: load });
      };
    }, O.failed);
  }

  O.person.meta().then(load, O.failed);
})();
