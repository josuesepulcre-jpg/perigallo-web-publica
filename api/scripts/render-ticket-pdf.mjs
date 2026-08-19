#!/usr/bin/env node
/*
 * Renderizador local del PDF de entradas. Lee un único JSON por stdin y emite
 * exclusivamente el PDF por stdout: nunca escribe códigos QR ni datos de
 * compradores en consola. Usa las mismas dependencias ya distribuidas para la
 * descarga del pedido en el navegador.
 */
import { createRequire } from "node:module";
import process from "node:process";

const require = createRequire(import.meta.url);
const { jsPDF } = require("../../assets/vendor/jspdf.umd.min.js");
const qrcode = require("../../assets/vendor/qrcode-generator.min.js");

let input = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => { input += chunk; });
process.stdin.on("end", () => {
  try {
    const data = JSON.parse(input);
    const tickets = Array.isArray(data.tickets) ? data.tickets : [];
    if (!tickets.length) throw new Error("No hay entradas para el documento.");
    const pdf = new jsPDF({ orientation: "portrait", unit: "mm", format: "a4", compress: true });
    tickets.forEach((ticket, index) => {
      if (index) pdf.addPage();
      drawTicket(pdf, data, ticket, index + 1, tickets.length);
    });
    process.stdout.write(Buffer.from(pdf.output("arraybuffer")));
  } catch (error) {
    // Solo un diagnóstico genérico: el proceso padre registra un error seguro.
    process.stderr.write("No se pudo generar el documento de entradas.\n");
    process.exitCode = 1;
  }
});

function drawTicket(pdf, order, ticket, index, total) {
  const deepTeal = [23, 50, 54];
  const cream = [246, 242, 230];
  const champagne = [210, 181, 150];
  const muted = [210, 213, 205];
  const title = safeText(ticket.event_title || "Perigallo");
  const subtitle = safeText(ticket.event_subtitle || "Experiencia Perigallo");
  const venue = safeText([ticket.location, ticket.locality].filter(Boolean).join(" · ") || "Ubicación por confirmar");
  const schedule = safeText(ticket.starts_at || "Fecha por confirmar");

  pdf.setFillColor(...deepTeal);
  pdf.rect(0, 0, 210, 297, "F");
  pdf.setDrawColor(...champagne);
  pdf.setLineWidth(0.3);
  pdf.rect(8, 7, 194, 283);
  pdf.setTextColor(...champagne);
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(8);
  pdf.setCharSpace(1.5);
  pdf.text("ENTRADA OFICIAL · PERIGALLO", 105, 24, { align: "center" });
  pdf.setCharSpace(0);
  pdf.setTextColor(...cream);
  pdf.setFont("times", "normal");
  pdf.setFontSize(29);
  const titleLines = pdf.splitTextToSize(title, 160).slice(0, 2);
  pdf.text(titleLines, 105, 45, { align: "center", lineHeightFactor: 1.02 });
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(11);
  pdf.setTextColor(...muted);
  pdf.text(pdf.splitTextToSize(subtitle, 155).slice(0, 2), 105, 64 + (titleLines.length - 1) * 10, { align: "center" });

  drawQr(pdf, String(ticket.qr_value || ""), 74, 84, 62, cream, deepTeal);
  pdf.setTextColor(...champagne);
  pdf.setFontSize(8);
  pdf.setCharSpace(1.05);
  pdf.text("PRESENTA ESTE CÓDIGO EN EL ACCESO", 105, 155, { align: "center" });
  pdf.setCharSpace(0);
  pdf.setTextColor(...cream);
  pdf.setFont("courier", "normal");
  pdf.setFontSize(10);
  pdf.text(safeText(ticket.public_code || "—"), 105, 164, { align: "center" });

  field(pdf, "FECHA Y HORA", schedule, 25, 184, 160, champagne, cream);
  field(pdf, "LUGAR", venue, 25, 213, 160, champagne, cream);
  field(pdf, "TIPO DE ENTRADA", safeText(ticket.ticket_type_name || "Entrada"), 25, 242, 76, champagne, cream);
  field(pdf, "ENTRADA", "" + index + " de " + total, 109, 242, 76, champagne, cream);
  pdf.setDrawColor(...champagne);
  pdf.line(25, 276, 185, 276);
  pdf.setTextColor(...muted);
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(7.5);
  pdf.text("PEDIDO " + safeText(order.reference || "—") + " · Perigallo", 105, 282, { align: "center" });
  if (order.is_test) {
    pdf.setTextColor(...champagne);
    pdf.text("ENTORNO DE PRUEBAS · SIN CARGO REAL", 105, 288, { align: "center" });
  }
}

function drawQr(pdf, value, x, y, size, background, foreground) {
  if (!value) throw new Error("Falta el QR de una entrada.");
  const qr = qrcode(0, "M");
  qr.addData(value, "Byte");
  qr.make();
  const modules = qr.getModuleCount();
  const quiet = 4;
  const cell = size / (modules + quiet * 2);
  pdf.setFillColor(...background);
  pdf.roundedRect(x, y, size, size, 2, 2, "F");
  pdf.setFillColor(...foreground);
  for (let row = 0; row < modules; row += 1) {
    for (let column = 0; column < modules; column += 1) {
      if (qr.isDark(row, column)) pdf.rect(x + (column + quiet) * cell, y + (row + quiet) * cell, cell + 0.03, cell + 0.03, "F");
    }
  }
}

function field(pdf, label, value, x, y, width, border, text) {
  pdf.setFillColor(31, 61, 65);
  pdf.setDrawColor(...border);
  pdf.setLineWidth(0.25);
  pdf.roundedRect(x, y, width, 22, 1.5, 1.5, "FD");
  pdf.setTextColor(...border);
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(7);
  pdf.setCharSpace(0.7);
  pdf.text(label, x + 7, y + 7);
  pdf.setCharSpace(0);
  pdf.setTextColor(...text);
  pdf.setFontSize(10);
  pdf.text(pdf.splitTextToSize(value, width - 14).slice(0, 2), x + 7, y + 15, { lineHeightFactor: 1.1 });
}

function safeText(value) {
  return String(value || "").replace(/[\u0000-\u001f\u007f]/g, " ").slice(0, 500);
}
