(function () {
  "use strict";
  var root = document.querySelector("[data-archive-admin]");
  if (!root) return;
  var editor = root.querySelector("[data-chapter-editor]"),
    list = root.querySelector("[data-chapter-list]"),
    notice = root.querySelector("[data-archive-status]");
  var sourceEvents = [],
    entries = [],
    current = null,
    csrf = "",
    dirty = false,
    busy = false,
    saving = false,
    filter = "all";
  var labels = {
      draft: "Borrador",
      published: "Publicada",
      archived: "Archivada",
    },
    types = {
      story: "Historia / texto",
      gallery: "Recorrido visual",
      gastronomy: "Gastronomía",
      highlights: "Momento destacado",
      video: "Vídeo resumen",
    };
  function esc(v) {
    return String(v == null ? "" : v).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }
  function status(message, error) {
    notice.textContent = message;
    notice.classList.toggle("is-error", !!error);
  }
  async function request(path, method, body) {
    var options = {
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf },
    };
    if (method) options.method = method;
    if (body instanceof FormData) options.body = body;
    else if (body !== undefined) {
      options.body = JSON.stringify(body);
      options.headers["Content-Type"] = "application/json";
    }
    var response = await fetch("/api" + path, options);
    var data = await response.json();
    if (!response.ok || !data.ok)
      throw new Error(data.error || "No se pudo completar la solicitud.");
    return data;
  }
  function setDirty() {
    dirty = true;
    editor.querySelectorAll("[data-preview]").forEach(function (a) {
      a.setAttribute("aria-disabled", "true");
      a.removeAttribute("href");
    });
    var badge = editor.querySelector("[data-save-state]");
    if (badge) badge.textContent = "Cambios sin guardar";
  }
  function field(name, label, value, type) {
    return (
      "<label>" +
      esc(label) +
      (type === "textarea"
        ? '<textarea rows="4" data-doc="' +
          name +
          '">' +
          esc(value) +
          "</textarea>"
        : '<input type="' +
          (type || "text") +
          '" data-doc="' +
          name +
          '" value="' +
          esc(value) +
          '">') +
      "</label>"
    );
  }
  function safeImage(url) {
    return (
      /^\/(?:assets\/|media\/|la-perigalla-01\/media\/)/.test(url) &&
      !url.includes("..")
    );
  }
  function thumb(url, alt) {
    return safeImage(url)
      ? '<img src="' +
          esc(url) +
          '" alt="' +
          esc(alt || "Recurso de la edición") +
          '" loading="lazy">'
      : "";
  }
  function drawList() {
    list.innerHTML =
      entries
        .filter(function (e) {
          return filter === "all" || e.status === filter || e.phase === filter;
        })
        .map(function (e) {
          return (
            '<button type="button" data-select="' +
            e.id +
            '" class="archive-admin-chapter ' +
            (current && current.id === e.id ? "is-selected" : "") +
            '"><span>' +
            esc(e.phase === "finished" ? "Finalizada" : "Próxima") +
            " · " +
            esc(labels[e.status]) +
            " · " +
            esc(e.document.date || "Sin fecha") +
            "</span><strong>" +
            esc(e.document.title) +
            "</strong><small>" +
            esc(e.document.subtitle) +
            "</small></button>"
          );
        })
        .join("") || "<p>No hay ediciones en esta vista.</p>";
  }
  async function refresh() {
    entries = (await request("/admin/experience-archive")).experiences;
    drawList();
  }
  function mediaEditor(item, bi, mi) {
    return (
      '<div class="archive-admin-media" data-media-row="' +
      mi +
      '">' +
      (item.kind === "image"
        ? thumb(item.url, item.alt)
        : '<span class="archive-admin-video-label">Vídeo</span>') +
      "<div><label>Archivo o enlace " +
      (item.kind === "image" ? "de imagen" : "de vídeo") +
      '<input data-media="url" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" value="' +
      esc(item.url) +
      '"></label><label>Texto alternativo / descripción<input data-media="alt" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" value="' +
      esc(item.alt) +
      '"></label><label>Pie de foto<input data-media="caption" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" value="' +
      esc(item.caption) +
      '"></label><div class="archive-admin-checks"><label><input type="checkbox" data-media="portrait" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" ' +
      (item.portrait ? "checked" : "") +
      '> Formato vertical</label><label><input type="checkbox" data-media="featured" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" ' +
      (item.featured ? "checked" : "") +
      '> Imagen destacada</label></div><div class="archive-admin-small-actions"><button type="button" data-action="media-up" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" ' +
      (mi === 0 ? "disabled" : "") +
      ' aria-label="Subir recurso ' +
      (mi + 1) +
      '">↑ Subir</button><button type="button" data-action="media-down" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '" ' +
      (mi === current.document.blocks[bi].media.length - 1 ? "disabled" : "") +
      ' aria-label="Bajar recurso ' +
      (mi + 1) +
      '">↓ Bajar</button><button type="button" data-action="media-remove" data-bi="' +
      bi +
      '" data-mi="' +
      mi +
      '">Quitar del bloque</button></div></div></div>'
    );
  }
  function blockEditor(b, i) {
    return (
      '<section class="archive-admin-block" data-block="' +
      i +
      '"><header><div><span class="ticket-eyebrow">Bloque ' +
      (i + 1) +
      "</span><h3>" +
      esc(types[b.type]) +
      '</h3></div><div class="archive-admin-small-actions"><button type="button" data-action="block-up" data-bi="' +
      i +
      '" ' +
      (i === 0 ? "disabled" : "") +
      ' aria-label="Subir bloque ' +
      (i + 1) +
      '">↑</button><button type="button" data-action="block-down" data-bi="' +
      i +
      '" ' +
      (i === current.document.blocks.length - 1 ? "disabled" : "") +
      ' aria-label="Bajar bloque ' +
      (i + 1) +
      '">↓</button><button type="button" data-action="block-remove" data-bi="' +
      i +
      '">Quitar</button></div></header><label>Título del bloque<input data-block-field="title" data-bi="' +
      i +
      '" value="' +
      esc(b.title) +
      '"></label><label>Texto del bloque<textarea rows="4" data-block-field="text" data-bi="' +
      i +
      '">' +
      esc(b.text) +
      "</textarea></label><div>" +
      b.media
        .map(function (m, j) {
          return mediaEditor(m, i, j);
        })
        .join("") +
      '</div><div class="archive-admin-small-actions"><label class="archive-upload">Subir fotografías<input type="file" accept="image/jpeg,image/png,image/webp,image/avif" multiple data-upload="block-image" data-bi="' +
      i +
      '"></label><label class="archive-upload">Subir vídeo<input type="file" accept="video/mp4,video/webm" data-upload="block-video" data-bi="' +
      i +
      '"></label><button type="button" data-action="video-link" data-bi="' +
      i +
      '">Añadir enlace a vídeo</button></div></section>'
    );
  }
  function drawEditor() {
    if (!current) return;
    var d = current.document;
    editor.innerHTML =
      '<form data-editor-form><div class="archive-admin-editor-heading"><div><span class="ticket-eyebrow">' +
      esc(current.id ? labels[current.status] : "Nueva historia") +
      "</span><h2>" +
      esc(d.title || "Un nuevo capítulo.") +
      "</h2><p data-save-state>" +
      (dirty ? "Cambios sin guardar" : "Todos los cambios guardados") +
      '</p></div><button class="ticket-btn primary" type="submit">Guardar borrador</button></div><div class="archive-admin-top-actions"><a class="ticket-btn" data-preview ' +
      (current.id && !dirty
        ? 'href="/experiencias/vividas/' +
          encodeURIComponent(current.slug) +
          '/?preview=1"'
        : 'aria-disabled="true"') +
      '>Previsualizar borrador guardado ↗</a><span>La publicación se confirma al final de la historia.</span></div><div class="archive-admin-fields">' +
      field("title", "Nombre oficial", d.title) +
      field("subtitle", "Subtítulo", d.subtitle) +
      field("edition", "Número o nombre de edición", d.edition) +
      field("date", "Fecha de celebración", d.date, "date") +
      '<label class="archive-wide">URL estable<input data-slug value="' +
      esc(current.slug) +
      '" ' +
      (current.id ? "readonly" : "") +
      ' placeholder="nombre-de-la-edicion"><small>Se conserva después de crear la experiencia.</small></label><label class="archive-wide">Evento de entradas vinculado (opcional)<select data-event-link><option value="">Sin vincular</option>' +
      sourceEvents
        .filter(function (e) {
          return !e.is_test || Number(e.id) === Number(current.event_id);
        })
        .map(function (e) {
          return (
            '<option value="' +
            Number(e.id) +
            '" ' +
            (Number(e.id) === Number(current.event_id) ? "selected" : "") +
            ">" +
            esc(e.title) +
            "</option>"
          );
        })
        .join("") +
      '</select><small>Vincula la historia para que la preparación automática no cree otra del mismo evento.</small></label><div class="archive-wide">' +
      field(
        "description",
        "Descripción de la tarjeta",
        d.description,
        "textarea",
      ) +
      '</div><div class="archive-wide">' +
      field("story", "La historia de la experiencia", d.story, "textarea") +
      '</div></div><section class="archive-admin-cover"><h3>La primera impresión.</h3>' +
      thumb(d.cover_image, d.cover_alt) +
      '<div class="archive-admin-fields">' +
      field("cover_image", "Imagen de portada", d.cover_image) +
      field("cover_alt", "Texto alternativo de portada", d.cover_alt) +
      field(
        "cover_video",
        "Vídeo principal / portada (opcional)",
        d.cover_video,
      ) +
      '</div><div class="archive-admin-small-actions"><label class="archive-upload">Elegir fotografía de portada<input type="file" accept="image/jpeg,image/png,image/webp,image/avif" data-upload="cover"></label><label class="archive-upload">Elegir vídeo principal<input type="file" accept="video/mp4,video/webm" data-upload="cover-video"></label></div><p class="archive-admin-help">Imágenes: hasta 5 MB y 16 MP; se conservan los originales y se optimiza una copia WebP. Vídeo: MP4 o WebM, hasta 50 MB. También puedes pegar un enlace de YouTube o Vimeo.</p></section><div data-blocks>' +
      d.blocks.map(blockEditor).join("") +
      '</div><div class="archive-admin-add-block"><label>Tipo de sección<select data-new-block-type>' +
      Object.keys(types)
        .map(function (t) {
          return '<option value="' + t + '">' + esc(types[t]) + "</option>";
        })
        .join("") +
      '</select></label><button class="ticket-btn" type="button" data-action="block-add">Añadir sección</button></div><details class="archive-admin-seo"><summary>Presentación en buscadores y redes</summary>' +
      field("seo_title", "Título SEO", d.seo_title) +
      field(
        "seo_description",
        "Descripción SEO",
        d.seo_description,
        "textarea",
      ) +
      '</details><footer class="archive-admin-editor-actions"><button class="ticket-btn primary" type="submit">Guardar borrador</button><a class="ticket-btn" data-preview ' +
      (current.id && !dirty
        ? 'href="/experiencias/vividas/' +
          encodeURIComponent(current.slug) +
          '/?preview=1"'
        : 'aria-disabled="true"') +
      '>Previsualizar borrador guardado ↗</a><button class="ticket-btn" type="button" data-action="publish" ' +
      (!current.id ? "disabled" : "") +
      ">Publicar historia</button>" +
      (current.status === "published"
        ? '<button class="ticket-btn" type="button" data-action="unpublish">Retirar publicación</button>'
        : "") +
      (current.status === "archived"
        ? '<button class="ticket-btn" type="button" data-action="restore">Recuperar borrador</button>'
        : current.id
          ? '<button class="ticket-btn" type="button" data-action="archive">Archivar historia</button>'
          : "") +
      '</footer><p class="archive-admin-help">Guardar no altera la versión pública. Publicar actualiza la historia visible. Las secciones vacías no se muestran fuera de esta vista previa.</p></form>';
  }
  async function select(entry) {
    if (dirty && !confirm("Hay cambios sin guardar. ¿Quieres descartarlos?"))
      return;
    try {
      current = (await request("/admin/experience-archive/" + entry.id))
        .experience;
      dirty = false;
      drawEditor();
      drawList();
      history.replaceState(null, "", "?id=" + entry.id);
    } catch (err) {
      status(err.message, true);
    }
  }
  async function save() {
    if (busy || saving) throw new Error("Espera a que termine la operación.");
    if (!current.document.title)
      throw new Error("Indica el nombre de la experiencia.");
    saving = true;
    var controls = Array.from(
      editor.querySelectorAll("input,textarea,select,button"),
    );
    controls.forEach(function (n) {
      n.disabled = true;
    });
    try {
      var data = await request(
        "/admin/experience-archive" + (current.id ? "/" + current.id : ""),
        current.id ? "PUT" : "POST",
        current,
      );
      current = data.experience;
      dirty = false;
      await refresh();
      drawEditor();
      history.replaceState(null, "", "?id=" + current.id);
      status("Borrador guardado. La versión pública permanece igual.");
      return current;
    } finally {
      saving = false;
      controls.forEach(function (n) {
        n.disabled = false;
      });
    }
  }
  editor.addEventListener("input", function (e) {
    var t = e.target;
    if (!current) return;
    if (t.hasAttribute("data-event-link"))
      current.event_id = t.value ? Number(t.value) : null;
    else if (t.dataset.doc) current.document[t.dataset.doc] = t.value;
    else if (t.hasAttribute("data-slug")) current.slug = t.value;
    else if (t.dataset.blockField)
      current.document.blocks[Number(t.dataset.bi)][t.dataset.blockField] =
        t.value;
    else if (t.dataset.media)
      current.document.blocks[Number(t.dataset.bi)].media[Number(t.dataset.mi)][
        t.dataset.media
      ] = t.type === "checkbox" ? t.checked : t.value;
    else return;
    setDirty();
  });
  editor.addEventListener("submit", function (e) {
    e.preventDefault();
    save().catch(function (err) {
      status(err.message, true);
    });
  });
  editor.addEventListener("click", async function (e) {
    var btn = e.target.closest("[data-action]");
    if (!btn || !current) return;
    var action = btn.dataset.action,
      bi = Number(btn.dataset.bi),
      mi = Number(btn.dataset.mi),
      blocks = current.document.blocks;
    try {
      if (["publish", "unpublish", "archive", "restore"].includes(action)) {
        if (
          !confirm(
            {
              publish: "¿Publicar esta versión de la historia?",
              unpublish:
                "¿Retirar esta historia del archivo público? Se conserva el contenido.",
              archive:
                "¿Archivar esta historia y retirarla de la web? Podrás recuperarla.",
              restore: "¿Recuperar esta historia como borrador?",
            }[action],
          )
        )
          return;
        if (dirty) await save();
        current = (
          await request(
            "/admin/experience-archive/" + current.id + "/" + action,
            "POST",
            { revision: current.revision },
          )
        ).experience;
        await refresh();
        drawEditor();
        status(
          action === "publish" ? "Historia publicada." : "Estado actualizado.",
        );
        return;
      }
      if (action === "block-add") {
        blocks.push({
          type: editor.querySelector("[data-new-block-type]").value,
          title: "",
          text: "",
          media: [],
        });
      }
      if (action === "block-remove") {
        if (
          !confirm(
            "¿Quitar este bloque del borrador? La versión publicada y los archivos se conservan.",
          )
        )
          return;
        blocks.splice(bi, 1);
      }
      if (action === "block-up" && bi > 0)
        [blocks[bi - 1], blocks[bi]] = [blocks[bi], blocks[bi - 1]];
      if (action === "block-down" && bi < blocks.length - 1)
        [blocks[bi + 1], blocks[bi]] = [blocks[bi], blocks[bi + 1]];
      if (action === "video-link")
        blocks[bi].media.push({
          kind: "video",
          url: "",
          alt: "",
          caption: "",
          portrait: false,
          featured: true,
        });
      if (action === "media-remove") {
        if (
          !confirm(
            "¿Quitar este recurso del bloque? El archivo original se conserva.",
          )
        )
          return;
        blocks[bi].media.splice(mi, 1);
      }
      if (action === "media-up" && mi > 0)
        [blocks[bi].media[mi - 1], blocks[bi].media[mi]] = [
          blocks[bi].media[mi],
          blocks[bi].media[mi - 1],
        ];
      if (action === "media-down" && mi < blocks[bi].media.length - 1)
        [blocks[bi].media[mi + 1], blocks[bi].media[mi]] = [
          blocks[bi].media[mi],
          blocks[bi].media[mi + 1],
        ];
      setDirty();
      drawEditor();
    } catch (err) {
      status(err.message, true);
    }
  });
  editor.addEventListener("change", async function (e) {
    var input = e.target;
    if (!input.dataset.upload || !input.files.length || busy) return;
    busy = true;
    var bi = Number(input.dataset.bi),
      kind = input.dataset.upload;
    status("Subiendo y preparando el contenido…");
    editor.querySelectorAll("button,input[type=file]").forEach(function (b) {
      b.disabled = true;
    });
    try {
      for (var file of input.files) {
        var form = new FormData();
        form.append("file", file);
        form.append("kind", kind.includes("video") ? "video" : "image");
        var media = (
          await request("/admin/experience-archive/media", "POST", form)
        ).media;
        if (kind === "cover") current.document.cover_image = media.url;
        else if (kind === "cover-video")
          current.document.cover_video = media.url;
        else
          current.document.blocks[bi].media.push({
            kind: media.media_type,
            url: media.url,
            alt: "",
            caption: "",
            portrait: !!media.height && media.height > media.width,
            featured: false,
          });
        setDirty();
      }
      status(
        "Contenido incorporado. Añade su descripción y guarda el borrador.",
      );
    } catch (err) {
      status(err.message, true);
    } finally {
      busy = false;
      drawEditor();
    }
  });
  list.addEventListener("click", function (e) {
    var b = e.target.closest("[data-select]");
    if (b && !busy && !saving)
      select(
        entries.find(function (x) {
          return x.id === Number(b.dataset.select);
        }),
      );
  });
  root
    .querySelector("[data-new-chapter]")
    .addEventListener("click", function () {
      if (busy || saving) return;
      if (dirty && !confirm("¿Descartar los cambios sin guardar?")) return;
      current = {
        slug: "",
        status: "draft",
        document: {
          title: "",
          subtitle: "",
          edition: "",
          date: "",
          description: "",
          story: "",
          cover_image: "",
          cover_alt: "",
          cover_video: "",
          seo_title: "",
          seo_description: "",
          legacy_url: "",
          blocks: [],
        },
      };
      dirty = false;
      drawEditor();
      history.replaceState(null, "", location.pathname);
    });
  root
    .querySelector("[data-chapter-filter]")
    .addEventListener("change", function (e) {
      filter = e.target.value;
      drawList();
    });
  root
    .querySelector("[data-sync-completed]")
    .addEventListener("click", async function () {
      try {
        var d = await request("/admin/experience-archive/sync", "POST", {});
        await refresh();
        status(
          d.created +
            " borradores preparados. No se ha publicado ninguna historia.",
        );
      } catch (err) {
        status(err.message, true);
      }
    });
  window.addEventListener("beforeunload", function (e) {
    if (dirty || busy) {
      e.preventDefault();
      e.returnValue = "";
    }
  });
  request("/admin/session")
    .then(async function (s) {
      if (!s.authenticated) {
        location.replace(
          "/admin/login/?next=" +
            encodeURIComponent(location.pathname + location.search),
        );
        return;
      }
      if (s.role === "control_acceso") {
        editor.textContent = "Tu rol no permite editar el archivo.";
        return;
      }
      csrf = s.csrf;
      sourceEvents = (await request("/admin/events")).events;
      await refresh();
      var id = Number(new URLSearchParams(location.search).get("id"));
      var entry = entries.find(function (e) {
        return e.id === id;
      });
      if (entry) select(entry);
    })
    .catch(function (err) {
      status(err.message, true);
    });
})();
