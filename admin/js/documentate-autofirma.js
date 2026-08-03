(() => {
  // node_modules/@erseco/autofirma-client/dist/index.js
  var AutoFirmaError = class extends Error {
    code;
    nativeType;
    nativeMessage;
    constructor(message, code = "AUTOFIRMA_ERROR", nativeType, nativeMessage) {
      super(message);
      this.name = "AutoFirmaError";
      this.code = code;
      this.nativeType = nativeType;
      this.nativeMessage = nativeMessage;
    }
  };
  var AutoScriptUnavailableError = class extends AutoFirmaError {
    constructor() {
      super(
        "AutoScript no est\xE1 disponible en la p\xE1gina.",
        "AUTOSCRIPT_UNAVAILABLE"
      );
      this.name = "AutoScriptUnavailableError";
    }
  };
  function fromNativeError(nativeType, nativeMessage) {
    const normalizedType = nativeType.toLowerCase();
    const normalizedMessage = nativeMessage.toLowerCase();
    if (normalizedType.includes("cancel") || normalizedMessage.includes("cancel")) {
      return new AutoFirmaError(
        "La operaci\xF3n de firma fue cancelada.",
        "USER_CANCELLED",
        nativeType,
        nativeMessage
      );
    }
    if (normalizedType.includes("outofmemory")) {
      return new AutoFirmaError(
        "El fichero excede la memoria disponible de AutoFirma.",
        "DATA_TOO_LARGE",
        nativeType,
        nativeMessage
      );
    }
    if (normalizedType.includes("timeout")) {
      return new AutoFirmaError(
        "AutoFirma no respondi\xF3 a tiempo.",
        "NATIVE_TIMEOUT",
        nativeType,
        nativeMessage
      );
    }
    return new AutoFirmaError(
      "AutoFirma no pudo completar la operaci\xF3n.",
      "NATIVE_ERROR",
      nativeType,
      nativeMessage
    );
  }
  function isAutoScriptApi(candidate) {
    return typeof candidate === "object" && candidate !== null && typeof candidate.sign === "function";
  }
  function resolveAutoScript(injected) {
    if (injected) {
      return injected;
    }
    if (typeof window !== "undefined" && isAutoScriptApi(window.AutoScript)) {
      return window.AutoScript;
    }
    throw new AutoScriptUnavailableError();
  }
  function invokeSignatureOperation(operation, data, algorithm, format, parameters) {
    return new Promise((resolve, reject) => {
      operation(
        data,
        algorithm,
        format,
        parameters,
        (signature, certificate, extraData) => {
          resolve({
            signature,
            ...certificate ? { certificate } : {},
            ...extraData ? { extraData } : {}
          });
        },
        (errorType, errorMessage) => {
          reject(fromNativeError(errorType, errorMessage));
        }
      );
    });
  }
  function serializeParameters(parameters = {}) {
    return Object.entries(parameters).filter((entry) => {
      return entry[1] !== void 0 && entry[1] !== null;
    }).map(([key, value]) => {
      if (/[\r\n=]/u.test(key)) {
        throw new TypeError(`Nombre de par\xE1metro no v\xE1lido: ${key}`);
      }
      const serializedValue = String(value).replace(/[\r\n]+/gu, " ");
      return `${key}=${serializedValue}`;
    }).join("\n");
  }
  async function toBase64(data) {
    if (typeof data === "string") {
      return data;
    }
    if (typeof Blob !== "undefined" && data instanceof Blob) {
      return bytesToBase64(new Uint8Array(await data.arrayBuffer()));
    }
    if (data instanceof ArrayBuffer) {
      return bytesToBase64(new Uint8Array(data));
    }
    if (data instanceof Uint8Array) {
      return bytesToBase64(data);
    }
    throw new TypeError("Tipo de dato no compatible.");
  }
  function bytesToBase64(bytes) {
    let binary = "";
    const chunkSize = 32768;
    for (let offset = 0; offset < bytes.length; offset += chunkSize) {
      binary += String.fromCharCode(
        ...bytes.subarray(offset, offset + chunkSize)
      );
    }
    if (typeof btoa === "function") {
      return btoa(binary);
    }
    throw new Error("El entorno no proporciona una funci\xF3n Base64 compatible.");
  }
  var DEFAULT_ALGORITHM = "SHA256withRSA";
  var AutoFirmaClient = class {
    autoScript;
    constructor(options = {}) {
      this.autoScript = resolveAutoScript(options.autoScript);
      if (options.storageUrl && options.retrieveUrl && this.autoScript.setServlets) {
        this.autoScript.setServlets(options.storageUrl, options.retrieveUrl);
      }
    }
    /**
     * Solicita a AutoScript que prepare o abra AutoFirma.
     */
    initialize() {
      this.autoScript.cargarAppAfirma?.();
    }
    /**
     * Firma los datos proporcionados.
     */
    sign(options) {
      return this.execute(this.autoScript.sign, options);
    }
    /**
     * Añade una firma al mismo nivel cuando AutoScript expone la operación.
     */
    coSign(options) {
      return this.executeRequired("coSign", this.autoScript.coSign, options);
    }
    /**
     * Contrafirma cuando AutoScript expone la operación.
     */
    counterSign(options) {
      return this.executeRequired(
        "counterSign",
        this.autoScript.counterSign,
        options
      );
    }
    /**
     * Abre la selección de certificado sin iniciar una firma.
     */
    selectCertificate(parameters = {}) {
      if (!this.autoScript.selectCertificate) {
        return this.unsupported("selectCertificate");
      }
      return new Promise((resolve, reject) => {
        this.autoScript.selectCertificate?.(
          serializeParameters(parameters),
          (certificate) => resolve({ certificate }),
          (type, message) => reject(fromNativeError(type, message))
        );
      });
    }
    /**
     * Pide a AutoFirma que guarde datos en un fichero elegido por la persona
     * usuaria.
     */
    saveDataToFile(options) {
      const operation = this.autoScript.saveDataToFile;
      if (!operation) {
        return this.unsupported("saveDataToFile");
      }
      return new Promise((resolve, reject) => {
        operation(
          options.data,
          options.title,
          options.filename,
          options.extension,
          options.description,
          () => resolve(),
          (type, message) => reject(fromNativeError(type, message))
        );
      });
    }
    /**
     * Comprueba la sincronía del reloj del equipo contra un servidor.
     *
     * AutoScript lo implementa con una petición XHR **síncrona** que bloquea el
     * hilo principal (`xhr.open('GET', url, false)`) hasta obtener respuesta.
     * Si no se indica `checkUrl`, la petición se envía contra
     * `document.URL + '/' + Math.random()`: una URL inventada contra el propio
     * origen de la página, un acceso de red no documentado en ningún otro sitio
     * y que este método no evita ni controla, pese a que esta librería no hace
     * ningún acceso de red propio.
     *
     * El único efecto observable de un desfase es un `alert()` nativo; AutoScript
     * captura y silencia cualquier error (por ejemplo, que la petición falle).
     * Por eso la promesa devuelta nunca informa del resultado de la
     * comprobación: se resuelve siempre que la operación exista, haya o no
     * desfase y haya o no error de red.
     *
     * Con `checkType: "CT_OBLIGATORY"` y un desfase detectado, AutoScript marca
     * un estado interno (`severeTimeDelay`) que hace que su función de carga
     * (`cargarAppAfirma`, a la que invoca `initialize()`) registre un aviso y
     * retorne sin hacer nada la siguiente vez que se ejecute. El orden de
     * llamadas importa y no está documentado: invocar
     * `checkTime({ checkType: "CT_OBLIGATORY" })` antes de `initialize()` puede
     * convertir `initialize()` en un no-op silencioso; invocarlo después de
     * `initialize()` no afecta a una carga que ya se ha iniciado.
     *
     * Si no se indica `maxMillis`, se reenvía tal cual: AutoScript aplica
     * entonces su propio valor por defecto (300000 ms, 5 minutos) en vez de uno
     * impuesto aquí.
     */
    checkTime(options = {}) {
      const operation = this.autoScript.checkTime;
      if (!operation) {
        return this.unsupported("checkTime");
      }
      operation(
        options.checkType ?? "CT_RECOMMENDED",
        options.maxMillis,
        options.checkUrl
      );
      return Promise.resolve();
    }
    /**
     * Devuelve el objeto oficial para casos que el wrapper todavía no cubra.
     */
    get raw() {
      return this.autoScript;
    }
    /**
     * Valida que exista la operación opcional antes de ejecutarla.
     */
    executeRequired(name, operation, options) {
      if (!operation) {
        return this.unsupported(name);
      }
      return this.execute(operation, options);
    }
    /**
     * Rechazo homogéneo para operaciones ausentes en la versión fijada.
     */
    unsupported(name) {
      return Promise.reject(
        new AutoFirmaError(
          `Esta versi\xF3n de AutoScript no expone ${name}.`,
          "UNSUPPORTED_OPERATION"
        )
      );
    }
    /**
     * Normaliza datos y parámetros antes de delegar en AutoScript.
     */
    async execute(operation, options) {
      return invokeSignatureOperation(
        operation,
        await toBase64(options.data),
        options.algorithm ?? DEFAULT_ALGORITHM,
        options.format,
        serializeParameters(options.parameters)
      );
    }
  };

  // assets/js/documentate-autofirma.js
  (function() {
    "use strict";
    const config = window.documentateActionsConfig || {};
    const autoFirmaConfig = window.documentateAutoFirmaConfig || {};
    const strings = config.strings || {};
    const nativeAnchorClick = window.HTMLAnchorElement.prototype.click;
    let pendingBrowserSignature = null;
    function hasUnsavedChanges() {
      if (window.wp && wp.data && wp.data.select && wp.data.select("core/editor")) {
        return wp.data.select("core/editor").isEditedPostDirty();
      }
      if (window.wp && wp.autosave && wp.autosave.server && typeof wp.autosave.server.isDirty === "function") {
        return wp.autosave.server.isDirty();
      }
      if (window.tinymce && Array.isArray(window.tinymce.editors)) {
        return window.tinymce.editors.some((editor) => editor.isDirty());
      }
      return false;
    }
    function arrayBufferToBase64(buffer) {
      const bytes = new Uint8Array(buffer);
      const chunks = [];
      const chunkSize = 8192;
      for (let offset = 0; offset < bytes.length; offset += chunkSize) {
        chunks.push(
          String.fromCharCode.apply(
            null,
            bytes.subarray(offset, offset + chunkSize)
          )
        );
      }
      return window.btoa(chunks.join(""));
    }
    function base64ToPdfBlob(base64) {
      const binary = window.atob(base64);
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
      }
      return new Blob([bytes], { type: "application/pdf" });
    }
    async function generatePdf() {
      const body = new URLSearchParams({
        action: "documentate_generate_document",
        post_id: String(config.postId),
        format: "pdf",
        output: "download",
        _wpnonce: config.nonce
      });
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
        },
        body: body.toString()
      });
      const payload = await response.json();
      if (!response.ok || !payload.success || !payload.data?.url) {
        throw new Error(
          payload.data?.message || strings.errorGeneric || "No se pudo generar el documento."
        );
      }
      return payload.data.url;
    }
    async function openIntermediateSession() {
      if (!autoFirmaConfig.intermediateSessionUrl) {
        return {};
      }
      try {
        const response = await fetch(autoFirmaConfig.intermediateSessionUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": autoFirmaConfig.restNonce
          }
        });
        const session = await response.json();
        if (!response.ok) {
          return {};
        }
        return {
          storageUrl: session.storageUrl,
          retrieveUrl: session.retrieveUrl
        };
      } catch (error) {
        return {};
      }
    }
    function buildSignatureParameters() {
      const position = autoFirmaConfig.position || {};
      const text = position.text || autoFirmaConfig.signatureText || "Firmado por $$SUBJECTCN$$ el d\xEDa $$SIGNDATE=dd/MM/yyyy$$ con un certificado emitido por $$ISSUERCN$$";
      return {
        mode: "implicit",
        signatureSubFilter: "ETSI.CAdES.detached",
        filters: "nonexpired:",
        signaturePage: position.page,
        signaturePositionOnPageLowerLeftX: position.lowerLeftX,
        signaturePositionOnPageLowerLeftY: position.lowerLeftY,
        signaturePositionOnPageUpperRightX: position.upperRightX,
        signaturePositionOnPageUpperRightY: position.upperRightY,
        layer2Text: text,
        layer2FontFamily: 1,
        layer2FontSize: 10,
        layer2FontColor: "black"
      };
    }
    async function signPdf(data) {
      const servlets = await openIntermediateSession();
      const client = new AutoFirmaClient(servlets);
      client.initialize();
      const result = await client.sign({
        data,
        format: "PAdES",
        algorithm: "SHA512withRSA",
        parameters: buildSignatureParameters()
      });
      return result.signature;
    }
    function downloadSignedPdf(signedBase64) {
      const url = URL.createObjectURL(base64ToPdfBlob(signedBase64));
      const link = document.createElement("a");
      const slug = config.postSlug || "documento";
      link.href = url;
      link.download = `${slug}-${config.postId}-signed.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1e3);
    }
    function restoreButton(button, originalHtml) {
      button.innerHTML = originalHtml;
      button.removeAttribute("aria-disabled");
      button.classList.remove("disabled");
    }
    function showSigningError(error) {
      if (error.name !== "es.gob.afirma.core.AOCancelledOperationException") {
        window.alert(
          error.message || strings.errorGeneric || "No se pudo firmar el documento."
        );
      }
    }
    async function signGeneratedPdf(url, button, originalHtml) {
      try {
        button.textContent = strings.signingInProgress || "Selecciona tu certificado en AutoFirma...";
        const response = await fetch(url, { credentials: "same-origin" });
        if (!response.ok) {
          throw new Error(
            strings.errorGeneric || "No se pudo descargar el PDF generado."
          );
        }
        const signed = await signPdf(
          arrayBufferToBase64(await response.arrayBuffer())
        );
        downloadSignedPdf(signed);
      } catch (error) {
        showSigningError(error);
      } finally {
        restoreButton(button, originalHtml);
      }
    }
    function inheritPdfConversionAttributes() {
      const signButton = document.querySelector(
        '[data-documentate-action="sign"]'
      );
      const pdfButton = document.querySelector(
        '.documentate-action-btn--pdf[data-documentate-action="download"]'
      );
      if (!signButton || !pdfButton) {
        return;
      }
      [
        "data-documentate-cdn-mode",
        "data-documentate-source-format"
      ].forEach((attribute) => {
        if (pdfButton.hasAttribute(attribute)) {
          signButton.setAttribute(attribute, pdfButton.getAttribute(attribute));
        }
      });
    }
    function removeReservedSignField() {
      document.querySelectorAll(
        '[name="documentate_field_sign"], #documentate_field_sign'
      ).forEach((field) => {
        const container = field.closest(
          "tr, .documentate-field, .form-field"
        );
        (container || field).remove();
      });
    }
    function usesBrowserConversion(button) {
      const sourceFormat = button.getAttribute(
        "data-documentate-source-format"
      );
      const cdnMode = button.getAttribute("data-documentate-cdn-mode") === "1";
      return Boolean(sourceFormat && (cdnMode || config.collaboraPlayground));
    }
    function prepareBrowserSignature(button) {
      const originalHtml = button.innerHTML;
      button.setAttribute("aria-disabled", "true");
      button.classList.add("disabled");
      const timeoutId = window.setTimeout(() => {
        if (pendingBrowserSignature && pendingBrowserSignature.button === button) {
          restoreButton(button, originalHtml);
          pendingBrowserSignature = null;
        }
      }, 12e4);
      pendingBrowserSignature = { button, originalHtml, timeoutId };
    }
    window.HTMLAnchorElement.prototype.click = function() {
      if (pendingBrowserSignature && this.href?.startsWith("blob:") && this.download?.toLowerCase().endsWith(".pdf")) {
        const pending = pendingBrowserSignature;
        pendingBrowserSignature = null;
        window.clearTimeout(pending.timeoutId);
        signGeneratedPdf(this.href, pending.button, pending.originalHtml);
        return;
      }
      nativeAnchorClick.call(this);
    };
    async function handleSignClick(event) {
      const button = event.target.closest(
        '[data-documentate-action="sign"]'
      );
      if (!button || button.classList.contains("disabled")) {
        return;
      }
      if (usesBrowserConversion(button)) {
        prepareBrowserSignature(button);
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      if (hasUnsavedChanges()) {
        const message = strings.unsavedChanges || "Tienes cambios sin guardar. \xBFQuieres firmar la \xFAltima versi\xF3n guardada?";
        if (!window.confirm(message)) {
          return;
        }
      }
      const originalHtml = button.innerHTML;
      button.setAttribute("aria-disabled", "true");
      button.classList.add("disabled");
      try {
        button.textContent = strings.generating || "Generando documento...";
        const pdfUrl = await generatePdf();
        await signGeneratedPdf(pdfUrl, button, originalHtml);
      } catch (error) {
        showSigningError(error);
        restoreButton(button, originalHtml);
      }
    }
    inheritPdfConversionAttributes();
    removeReservedSignField();
    document.addEventListener("click", handleSignClick, true);
  })();
})();
//# sourceMappingURL=documentate-autofirma.js.map
