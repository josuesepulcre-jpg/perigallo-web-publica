// Run from the repository root: swift scripts/build-favicons.swift
// Export the existing brand mark; no external resources are needed by the icons.
import AppKit
import Foundation

let root = URL(fileURLWithPath: FileManager.default.currentDirectoryPath)
let source = root.appendingPathComponent("assets/images/perigallo-logo-original.png")
guard let logo = NSImage(contentsOf: source) else { fatalError("Cannot load brand logo") }
let icons = root.appendingPathComponent("assets/icons")
try FileManager.default.createDirectory(at: icons, withIntermediateDirectories: true)

func png(size: Int, rounded: Bool = true) -> Data {
    let bitmap = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: size,
        pixelsHigh: size, bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true,
        isPlanar: false, colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0)!
    let context = NSGraphicsContext(bitmapImageRep: bitmap)!
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = context
    context.imageInterpolation = .high
    let edge = CGFloat(size)
    let bounds = NSRect(x: 0, y: 0, width: edge, height: edge)
    NSColor(srgbRed: 39 / 255, green: 66 / 255, blue: 73 / 255, alpha: 1).setFill()
    NSBezierPath(roundedRect: bounds, xRadius: rounded ? edge * 0.18 : 0,
        yRadius: rounded ? edge * 0.18 : 0).fill()
    let width = edge * 0.88
    let height = width * logo.size.height / logo.size.width
    logo.draw(in: NSRect(x: (edge - width) / 2, y: (edge - height) / 2,
        width: width, height: height), from: .zero, operation: .sourceOver, fraction: 1)
    NSGraphicsContext.restoreGraphicsState()
    return bitmap.representation(using: .png, properties: [:])!
}

let sizes = [16, 32, 48]
let frames = sizes.map { png(size: $0) }
for (size, data) in zip(sizes, frames) {
    try data.write(to: icons.appendingPathComponent("favicon-\(size).png"))
}
try png(size: 180, rounded: false).write(to: root.appendingPathComponent("apple-touch-icon.png"))

func littleEndian(_ value: Int, bytes: Int) -> Data {
    Data((0..<bytes).map { UInt8((value >> ($0 * 8)) & 255) })
}
var ico = Data([0, 0, 1, 0])
ico.append(littleEndian(sizes.count, bytes: 2))
var offset = 6 + 16 * sizes.count
for (size, frame) in zip(sizes, frames) {
    ico.append(Data([UInt8(size), UInt8(size), 0, 0]))
    ico.append(littleEndian(1, bytes: 2))
    ico.append(littleEndian(32, bytes: 2))
    ico.append(littleEndian(frame.count, bytes: 4))
    ico.append(littleEndian(offset, bytes: 4))
    offset += frame.count
}
frames.forEach { ico.append($0) }
try ico.write(to: root.appendingPathComponent("favicon.ico"))

let embedded = png(size: 512).base64EncodedString()
let svg = """
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
  <image width="512" height="512" href="data:image/png;base64,\(embedded)"/>
</svg>

"""
try svg.write(to: root.appendingPathComponent("favicon.svg"), atomically: true, encoding: .utf8)
try svg.write(to: root.appendingPathComponent("la-perigalla-01/favicon.svg"), atomically: true, encoding: .utf8)
print("Exported self-contained Perigallo SVG, ICO, PNG and Apple touch icons.")
