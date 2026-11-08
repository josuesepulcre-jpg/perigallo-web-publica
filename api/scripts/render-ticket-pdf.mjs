#!/usr/bin/env node
/*
 * Renderizador local del PDF de entradas. Lee un único JSON por stdin y emite
 * exclusivamente el PDF por stdout: nunca escribe códigos QR ni datos de
 * compradores en consola. Usa las mismas dependencias ya distribuidas para la
 * descarga del pedido en el navegador.
 */
import { createRequire } from "node:module";
import process from "node:process";
import { drawTicketPdf, TICKET_PAGE } from "../../assets/js/ticket-pdf-layout.mjs";
import { readFileSync } from "node:fs";
const font = readFileSync(new URL("../../brand/Geist-Regular.ttf", import.meta.url)).toString("base64");
const logo = readFileSync(new URL("../../brand/perigallo-lockup-white.png", import.meta.url)).toString("base64");

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
    const pdf = new jsPDF({ orientation: "portrait", unit: "mm", format: TICKET_PAGE, compress: true });
    pdf.addFileToVFS("Geist-Regular.ttf", font);
    pdf.addFont("Geist-Regular.ttf", "Geist", "normal");
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
  drawTicketPdf(pdf, order, ticket, index, total, {
    logo: "data:image/png;base64," + logo,
    fontFamily: "Geist",
    drawQr: (pdf,x,y,size) => drawQr(pdf, String(ticket.qr_value || ""), x,y,size,[255,255,255],[20,20,20]),
  });
}

function drawQr(pdf, value, x, y, size, background, foreground) {
  if (!value) throw new Error("Falta el QR de una entrada.");
  const qr = qrcode(0, "M");
  qr.addData(value, "Byte");
  qr.make();
  const modules = qr.getModuleCount();
  const quiet = 0; // The shared layout supplies a 4 mm quiet zone.
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
