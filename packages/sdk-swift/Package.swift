// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "ErrataSDK",
    platforms: [
        .iOS(.v14),
        .macOS(.v11),
        .tvOS(.v14),
        .watchOS(.v7)
    ],
    products: [
        .library(
            name: "ErrataSDK",
            targets: ["ErrataSDK"]
        ),
    ],
    dependencies: [
        .package(url: "https://github.com/microsoft/plcrashreporter.git", from: "1.11.0"),
        .package(url: "https://github.com/apple/swift-log.git", from: "1.5.0"),
    ],
    targets: [
        .target(
            name: "ErrataSDK",
            dependencies: [
                .product(name: "CrashReporter", package: "plcrashreporter"),
                .product(name: "Logging", package: "swift-log"),
            ],
            path: "Sources/ErrataSDK"
        ),
        .testTarget(
            name: "ErrataSDKTests",
            dependencies: ["ErrataSDK"],
            path: "Tests/ErrataSDKTests"
        ),
    ]
)
