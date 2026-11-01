// Vite resolves `?url` imports to the built asset's public URL.
declare module '*?url' {
    const url: string;
    export default url;
}
