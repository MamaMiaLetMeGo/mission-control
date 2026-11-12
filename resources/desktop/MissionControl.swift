import AppKit
import Darwin
import Foundation

private enum Palette {
    static let canvas = NSColor(srgbRed: 0.969, green: 0.973, blue: 0.980, alpha: 1)
    static let ink = NSColor(srgbRed: 0.15, green: 0.16, blue: 0.20, alpha: 1)
    static let accent = NSColor(srgbRed: 0.443, green: 0.349, blue: 0.733, alpha: 1)
    static let border = NSColor(srgbRed: 0.89, green: 0.90, blue: 0.92, alpha: 1)
}

private func missionControlIcon(size: CGFloat) -> NSImage {
    NSImage(size: NSSize(width: size, height: size), flipped: false) { bounds in
        let tile = bounds.insetBy(dx: size * 0.07, dy: size * 0.07)
        let background = NSBezierPath(roundedRect: tile, xRadius: size * 0.19, yRadius: size * 0.19)
        NSGradient(starting: NSColor(srgbRed: 0.51, green: 0.41, blue: 0.80, alpha: 1), ending: Palette.accent)?.draw(in: background, angle: -90)
        NSColor.white.setStroke()
        let wave = NSBezierPath()
        wave.lineWidth = size * 0.045
        wave.lineCapStyle = .round
        for (index, height) in [0.13, 0.34, 0.52, 0.34, 0.13].enumerated() {
            let x = size * (0.27 + CGFloat(index) * 0.115)
            wave.move(to: NSPoint(x: x, y: size * (0.5 - height / 2)))
            wave.line(to: NSPoint(x: x, y: size * (0.5 + height / 2)))
        }
        wave.stroke()
        return true
    }
}

private struct LauncherConfiguration: Decodable {
    let repositoryPath: String
    let nodePath: String
    let runtimePath: String?
}

private enum ServiceState: String, Decodable {
    case stopped, starting, running, external, error

    var title: String {
        switch self {
        case .stopped: return "Stopped"
        case .starting: return "Starting"
        case .running: return "Running"
        case .external: return "Already running"
        case .error: return "Needs attention"
        }
    }

    var color: NSColor {
        switch self {
        case .stopped: return .secondaryLabelColor
        case .starting: return NSColor(srgbRed: 0.75, green: 0.48, blue: 0.14, alpha: 1)
        case .running: return NSColor(srgbRed: 0.20, green: 0.54, blue: 0.36, alpha: 1)
        case .external: return Palette.accent
        case .error: return NSColor(srgbRed: 0.72, green: 0.23, blue: 0.27, alpha: 1)
        }
    }
}

private struct RuntimeStatus: Decodable {
    let state: ServiceState
    let message: String
    let canStart: Bool
    let canStop: Bool
    let canOpen: Bool
    let url: String?
    let logDirectory: String?

    var dashboardURL: URL? {
        guard canOpen, let url, let components = URLComponents(string: url),
              components.scheme == "http", components.host == "127.0.0.1", components.port == 8765,
              components.user == nil, components.password == nil,
              components.query == nil, components.fragment == nil,
              components.path.isEmpty || components.path == "/" else { return nil }
        return components.url
    }

    var logsURL: URL? {
        guard let logDirectory, (logDirectory as NSString).isAbsolutePath else { return nil }
        return URL(fileURLWithPath: logDirectory, isDirectory: true)
    }
}

private enum RuntimeCommand: String {
    case status, start, stop

    var timeout: TimeInterval { self == .status ? 15 : 120 }
    var pendingTitle: String {
        switch self {
        case .status: return "Checking services"
        case .start: return "Starting Mission Control"
        case .stop: return "Stopping services"
        }
    }
    var pendingMessage: String {
        switch self {
        case .status: return "Checking your local workspace."
        case .start: return "Preparing your local workspace. Your dashboard will open when it is ready."
        case .stop: return "Shutting down the services managed by this launcher."
        }
    }
}

private enum RuntimeFailure: Error {
    case configuration, launch, timeout, invalidResponse

    var message: String {
        switch self {
        case .configuration: return "The launcher setup is incomplete. Reinstall the Mission Control desktop app, then open it again."
        case .launch: return "The launcher could not check local services. Check the logs and try again."
        case .timeout: return "Local services took too long to respond. Their status will be checked again automatically."
        case .invalidResponse: return "The launcher could not read the service status. Check the logs, or reinstall the desktop app if this continues."
        }
    }
}

private final class ProcessTimeout {
    private let lock = NSLock()
    private var value = false
    var occurred: Bool {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
    func mark() {
        lock.lock()
        value = true
        lock.unlock()
    }
}

private final class RuntimeClient {
    private let configuration: LauncherConfiguration
    private let configurationURL: URL
    private let runtimeURL: URL

    init(bundle: Bundle = .main) throws {
        guard let resources = bundle.resourceURL else { throw RuntimeFailure.configuration }
        configurationURL = resources.appendingPathComponent("launcher.json")
        guard let data = try? Data(contentsOf: configurationURL), data.count <= 65536,
              let configuration = try? JSONDecoder().decode(LauncherConfiguration.self, from: data),
              (configuration.repositoryPath as NSString).isAbsolutePath,
              (configuration.nodePath as NSString).isAbsolutePath,
              FileManager.default.isExecutableFile(atPath: configuration.nodePath) else { throw RuntimeFailure.configuration }
        self.configuration = configuration
        let runtimePath = configuration.runtimePath ?? resources.appendingPathComponent("runtime.mjs").path
        guard (runtimePath as NSString).isAbsolutePath,
              FileManager.default.isReadableFile(atPath: runtimePath) else { throw RuntimeFailure.configuration }
        var isDirectory: ObjCBool = false
        guard FileManager.default.fileExists(atPath: configuration.repositoryPath, isDirectory: &isDirectory),
              isDirectory.boolValue else { throw RuntimeFailure.configuration }
        runtimeURL = URL(fileURLWithPath: runtimePath)
    }

    func perform(_ command: RuntimeCommand) -> Result<RuntimeStatus, RuntimeFailure> {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: configuration.nodePath)
        process.arguments = [runtimeURL.path, command.rawValue, "--config", configurationURL.path]
        process.currentDirectoryURL = URL(fileURLWithPath: configuration.repositoryPath, isDirectory: true)
        let output = Pipe()
        process.standardOutput = output
        process.standardError = FileHandle.nullDevice
        process.standardInput = FileHandle.nullDevice
        do { try process.run() } catch { return .failure(.launch) }

        let timeout = ProcessTimeout()
        let deadline = DispatchWorkItem {
            guard process.isRunning else { return }
            timeout.mark()
            process.terminate()
            DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + 3) {
                if process.isRunning { kill(process.processIdentifier, SIGKILL) }
            }
        }
        DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + command.timeout, execute: deadline)
        var data = Data()
        var exceededLimit = false
        while true {
            let chunk = output.fileHandleForReading.readData(ofLength: 8192)
            if chunk.isEmpty { break }
            if data.count + chunk.count <= 65536 { data.append(chunk) }
            else { exceededLimit = true; if process.isRunning { process.terminate() } }
        }
        process.waitUntilExit()
        deadline.cancel()
        try? output.fileHandleForReading.close()
        if timeout.occurred { return .failure(.timeout) }
        guard !exceededLimit, let status = try? JSONDecoder().decode(RuntimeStatus.self, from: data),
              !status.message.isEmpty, status.message.count <= 1000 else { return .failure(.invalidResponse) }
        return .success(status)
    }
}

private final class LauncherDelegate: NSObject, NSApplicationDelegate, NSWindowDelegate {
    private var window: NSWindow!
    private var client: RuntimeClient?
    private var timer: Timer?
    private var latestStatus: RuntimeStatus?
    private var inFlight = false
    private var activeCommand: RuntimeCommand?
    private var queuedCommand: RuntimeCommand?
    private var openWhenReady = false
    private var lastAnnouncedState: String?
    private let statusLabel = NSTextField(labelWithString: "Checking services")
    private let messageLabel = NSTextField(wrappingLabelWithString: "Checking your local workspace.")
    private let statusDot = NSView()
    private let spinner = NSProgressIndicator()
    private let startButton = NSButton(title: "Start Mission Control", target: nil, action: nil)
    private let openButton = NSButton(title: "Open dashboard", target: nil, action: nil)
    private let stopButton = NSButton(title: "Stop services", target: nil, action: nil)
    private let logsButton = NSButton(title: "Show logs", target: nil, action: nil)

    func applicationDidFinishLaunching(_ notification: Notification) {
        NSApp.appearance = NSAppearance(named: .aqua)
        NSApp.applicationIconImage = missionControlIcon(size: 512)
        configureMenu()
        configureWindow()
        do { client = try RuntimeClient() }
        catch { showFailure(.configuration) }
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)
        if client != nil {
            refreshStatus()
            timer = Timer.scheduledTimer(withTimeInterval: 3, repeats: true) { [weak self] _ in self?.refreshStatus() }
        }
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool { false }

    func applicationShouldHandleReopen(_ sender: NSApplication, hasVisibleWindows flag: Bool) -> Bool {
        showWindow()
        return true
    }

    func applicationWillTerminate(_ notification: Notification) { timer?.invalidate() }

    private func configureMenu() {
        let menu = NSMenu()
        let appItem = NSMenuItem()
        let appMenu = NSMenu(title: "Mission Control")
        appMenu.addItem(withTitle: "About Mission Control", action: #selector(NSApplication.orderFrontStandardAboutPanel(_:)), keyEquivalent: "")
        appMenu.addItem(.separator())
        appMenu.addItem(withTitle: "Hide Mission Control", action: #selector(NSApplication.hide(_:)), keyEquivalent: "h")
        appMenu.addItem(.separator())
        appMenu.addItem(withTitle: "Quit Mission Control", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        appItem.submenu = appMenu
        menu.addItem(appItem)
        let windowItem = NSMenuItem()
        let windowMenu = NSMenu(title: "Window")
        let showItem = NSMenuItem(title: "Show Mission Control", action: #selector(showWindow), keyEquivalent: "0")
        showItem.target = self
        windowMenu.addItem(showItem)
        windowItem.submenu = windowMenu
        menu.addItem(windowItem)
        NSApp.mainMenu = menu
        NSApp.windowsMenu = windowMenu
    }

    private func configureWindow() {
        window = NSWindow(contentRect: NSRect(x: 0, y: 0, width: 520, height: 400), styleMask: [.titled, .closable, .miniaturizable], backing: .buffered, defer: false)
        window.title = "Mission Control"
        window.titleVisibility = .hidden
        window.titlebarAppearsTransparent = true
        window.backgroundColor = Palette.canvas
        window.isReleasedWhenClosed = false
        window.delegate = self
        window.center()
        guard let content = window.contentView else { return }

        let icon = NSImageView(image: missionControlIcon(size: 128))
        icon.setAccessibilityElement(false)
        let title = NSTextField(labelWithString: "Mission Control")
        title.font = .systemFont(ofSize: 25, weight: .semibold)
        title.textColor = Palette.ink
        let subtitle = NSTextField(labelWithString: "Your local agent workspace")
        subtitle.font = .systemFont(ofSize: 13)
        subtitle.textColor = .secondaryLabelColor
        let identity = NSStackView(views: [title, subtitle])
        identity.orientation = .vertical
        identity.alignment = .leading
        identity.spacing = 4
        let header = NSStackView(views: [icon, identity])
        header.orientation = .horizontal
        header.alignment = .centerY
        header.spacing = 12

        let card = NSView()
        card.wantsLayer = true
        card.layer?.backgroundColor = NSColor.white.cgColor
        card.layer?.cornerRadius = 13
        card.layer?.borderColor = Palette.border.cgColor
        card.layer?.borderWidth = 1
        statusDot.wantsLayer = true
        statusDot.layer?.cornerRadius = 4
        statusDot.layer?.backgroundColor = NSColor.secondaryLabelColor.cgColor
        statusDot.setAccessibilityElement(false)
        statusLabel.font = .systemFont(ofSize: 14, weight: .semibold)
        statusLabel.textColor = Palette.ink
        messageLabel.font = .systemFont(ofSize: 13)
        messageLabel.textColor = .secondaryLabelColor
        messageLabel.maximumNumberOfLines = 4
        messageLabel.setContentCompressionResistancePriority(.defaultLow, for: .horizontal)
        spinner.style = .spinning
        spinner.controlSize = .small
        spinner.isDisplayedWhenStopped = false
        spinner.setAccessibilityLabel("Checking local services")

        for button in [startButton, openButton, stopButton, logsButton] {
            button.target = self
            button.bezelStyle = .rounded
            button.font = .systemFont(ofSize: 13, weight: .medium)
            button.isEnabled = false
        }
        startButton.action = #selector(startServices)
        startButton.bezelColor = Palette.accent
        startButton.contentTintColor = .white
        startButton.keyEquivalent = "\r"
        openButton.action = #selector(openDashboard)
        stopButton.action = #selector(stopServices)
        stopButton.isBordered = false
        stopButton.contentTintColor = .secondaryLabelColor
        stopButton.setAccessibilityHelp("Stops only the services managed by this launcher.")
        logsButton.action = #selector(showLogs)
        logsButton.isBordered = false
        logsButton.contentTintColor = .secondaryLabelColor

        let footer = NSTextField(wrappingLabelWithString: "Closing this window or quitting keeps services running.\nUse Stop services to shut down Mission Control.")
        footer.font = .systemFont(ofSize: 11.5)
        footer.textColor = .secondaryLabelColor
        footer.alignment = .center

        for view in [header, card, startButton, openButton, stopButton, logsButton, footer] {
            content.addSubview(view)
            view.translatesAutoresizingMaskIntoConstraints = false
        }
        for view in [statusDot, statusLabel, messageLabel, spinner] {
            card.addSubview(view)
            view.translatesAutoresizingMaskIntoConstraints = false
        }
        icon.translatesAutoresizingMaskIntoConstraints = false
        NSLayoutConstraint.activate([
            header.leadingAnchor.constraint(equalTo: content.leadingAnchor, constant: 27),
            header.topAnchor.constraint(equalTo: content.topAnchor, constant: 23),
            header.trailingAnchor.constraint(lessThanOrEqualTo: content.trailingAnchor, constant: -28),
            header.heightAnchor.constraint(equalToConstant: 60),
            icon.widthAnchor.constraint(equalToConstant: 60),
            icon.heightAnchor.constraint(equalToConstant: 60),
            card.leadingAnchor.constraint(equalTo: content.leadingAnchor, constant: 28),
            card.trailingAnchor.constraint(equalTo: content.trailingAnchor, constant: -28),
            card.topAnchor.constraint(equalTo: header.bottomAnchor, constant: 20),
            card.heightAnchor.constraint(equalToConstant: 118),
            statusDot.leadingAnchor.constraint(equalTo: card.leadingAnchor, constant: 18),
            statusDot.centerYAnchor.constraint(equalTo: statusLabel.centerYAnchor),
            statusDot.widthAnchor.constraint(equalToConstant: 8),
            statusDot.heightAnchor.constraint(equalToConstant: 8),
            statusLabel.leadingAnchor.constraint(equalTo: statusDot.trailingAnchor, constant: 9),
            statusLabel.topAnchor.constraint(equalTo: card.topAnchor, constant: 17),
            statusLabel.trailingAnchor.constraint(lessThanOrEqualTo: spinner.leadingAnchor, constant: -10),
            spinner.trailingAnchor.constraint(equalTo: card.trailingAnchor, constant: -18),
            spinner.centerYAnchor.constraint(equalTo: statusLabel.centerYAnchor),
            spinner.widthAnchor.constraint(equalToConstant: 16),
            spinner.heightAnchor.constraint(equalToConstant: 16),
            messageLabel.topAnchor.constraint(equalTo: statusLabel.bottomAnchor, constant: 9),
            messageLabel.leadingAnchor.constraint(equalTo: card.leadingAnchor, constant: 18),
            messageLabel.trailingAnchor.constraint(equalTo: card.trailingAnchor, constant: -18),
            messageLabel.bottomAnchor.constraint(lessThanOrEqualTo: card.bottomAnchor, constant: -15),
            startButton.topAnchor.constraint(equalTo: card.bottomAnchor, constant: 18),
            startButton.leadingAnchor.constraint(equalTo: card.leadingAnchor),
            startButton.heightAnchor.constraint(equalToConstant: 36),
            openButton.topAnchor.constraint(equalTo: startButton.topAnchor),
            openButton.leadingAnchor.constraint(equalTo: startButton.trailingAnchor, constant: 12),
            openButton.trailingAnchor.constraint(equalTo: card.trailingAnchor),
            openButton.widthAnchor.constraint(equalTo: startButton.widthAnchor),
            openButton.heightAnchor.constraint(equalTo: startButton.heightAnchor),
            stopButton.topAnchor.constraint(equalTo: startButton.bottomAnchor, constant: 8),
            stopButton.leadingAnchor.constraint(equalTo: startButton.leadingAnchor),
            stopButton.widthAnchor.constraint(equalTo: startButton.widthAnchor),
            stopButton.heightAnchor.constraint(equalToConstant: 28),
            logsButton.topAnchor.constraint(equalTo: stopButton.topAnchor),
            logsButton.leadingAnchor.constraint(equalTo: openButton.leadingAnchor),
            logsButton.widthAnchor.constraint(equalTo: openButton.widthAnchor),
            logsButton.heightAnchor.constraint(equalTo: stopButton.heightAnchor),
            footer.topAnchor.constraint(equalTo: stopButton.bottomAnchor, constant: 16),
            footer.leadingAnchor.constraint(equalTo: card.leadingAnchor),
            footer.trailingAnchor.constraint(equalTo: card.trailingAnchor),
            footer.bottomAnchor.constraint(lessThanOrEqualTo: content.bottomAnchor, constant: -20),
        ])
    }

    @objc private func showWindow() {
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)
        refreshStatus()
    }

    @objc private func startServices() {
        guard latestStatus?.canStart == true else { return }
        execute(.start)
    }

    @objc private func stopServices() {
        guard latestStatus?.canStop == true else { return }
        openWhenReady = false
        execute(.stop)
    }

    @objc private func openDashboard() {
        guard let url = latestStatus?.dashboardURL else { return }
        NSWorkspace.shared.open(url)
    }

    @objc private func showLogs() {
        guard let url = latestStatus?.logsURL else { return }
        NSWorkspace.shared.open(url)
    }

    private func refreshStatus() { execute(.status) }

    private func execute(_ command: RuntimeCommand) {
        guard let client else { return }
        if inFlight {
            if command != .status, activeCommand == .status, queuedCommand == nil {
                queuedCommand = command
                if command == .stop { openWhenReady = false }
                showPending(command)
            }
            return
        }
        inFlight = true
        activeCommand = command
        if command == .start { openWhenReady = true }
        else if command == .stop { openWhenReady = false }
        if command != .status || latestStatus == nil {
            showPending(command)
        }
        DispatchQueue.global(qos: .utility).async { [weak self] in
            let result = client.perform(command)
            DispatchQueue.main.async {
                guard let self else { return }
                self.inFlight = false
                self.activeCommand = nil
                self.spinner.stopAnimation(nil)
                switch result {
                case .success(let status):
                    self.latestStatus = status
                    if let queued = self.queuedCommand {
                        self.queuedCommand = nil
                        if (queued == .start && status.canStart) || (queued == .stop && status.canStop) {
                            self.execute(queued)
                            return
                        }
                        self.openWhenReady = false
                    }
                    self.display(status)
                    if self.openWhenReady, let url = status.dashboardURL {
                        self.openWhenReady = false
                        NSWorkspace.shared.open(url)
                    } else if status.state == .error || status.state == .stopped {
                        self.openWhenReady = false
                    }
                case .failure(let failure):
                    self.queuedCommand = nil
                    self.openWhenReady = false
                    self.showFailure(failure)
                }
            }
        }
    }

    private func showPending(_ command: RuntimeCommand) {
        setButtonsEnabled(false)
        statusLabel.stringValue = command.pendingTitle
        messageLabel.stringValue = command.pendingMessage
        statusDot.layer?.backgroundColor = ServiceState.starting.color.cgColor
        spinner.startAnimation(nil)
    }

    private func setButtonsEnabled(_ enabled: Bool) {
        startButton.isEnabled = enabled && latestStatus?.canStart == true
        stopButton.isEnabled = enabled && latestStatus?.canStop == true
        openButton.isEnabled = enabled && latestStatus?.dashboardURL != nil
        logsButton.isEnabled = enabled && latestStatus?.logsURL != nil
    }

    private func display(_ status: RuntimeStatus) {
        statusLabel.stringValue = status.state.title
        messageLabel.stringValue = status.message
        statusDot.layer?.backgroundColor = status.state.color.cgColor
        setButtonsEnabled(true)
        if status.state == .starting { spinner.startAnimation(nil) }
        if lastAnnouncedState != status.state.rawValue {
            lastAnnouncedState = status.state.rawValue
            NSAccessibility.post(element: statusLabel, notification: .valueChanged)
        }
    }

    private func showFailure(_ failure: RuntimeFailure) {
        statusLabel.stringValue = "Needs attention"
        messageLabel.stringValue = failure.message
        statusDot.layer?.backgroundColor = ServiceState.error.color.cgColor
        spinner.stopAnimation(nil)
        setButtonsEnabled(false)
        logsButton.isEnabled = latestStatus?.logsURL != nil
    }
}

private func renderIconIfRequested() -> Bool {
    let arguments = CommandLine.arguments
    guard arguments.dropFirst().first == "--render-icon" else { return false }
    guard arguments.count == 4, (arguments[2] as NSString).isAbsolutePath,
          let size = Int(arguments[3]), (16...2048).contains(size),
          let bitmap = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: size, pixelsHigh: size, bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false, colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0),
          let context = NSGraphicsContext(bitmapImageRep: bitmap) else {
        fputs("Usage: MissionControl --render-icon /absolute/icon.png SIZE\n", stderr)
        exit(2)
    }
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = context
    missionControlIcon(size: CGFloat(size)).draw(in: NSRect(x: 0, y: 0, width: size, height: size))
    NSGraphicsContext.restoreGraphicsState()
    guard let png = bitmap.representation(using: .png, properties: [:]) else { exit(1) }
    do { try png.write(to: URL(fileURLWithPath: arguments[2]), options: .atomic) }
    catch { fputs("Could not save the app icon.\n", stderr); exit(1) }
    return true
}

if !renderIconIfRequested() {
    let application = NSApplication.shared
    let delegate = LauncherDelegate()
    application.delegate = delegate
    application.setActivationPolicy(.regular)
    application.run()
}
