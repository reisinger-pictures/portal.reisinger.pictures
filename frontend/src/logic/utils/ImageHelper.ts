export const getCompressedBase64 = async (imageUrl: string, maxSide = 2048): Promise<string> => {
    const res = await fetch(imageUrl);
    const blob = await res.blob();
    const objectUrl = URL.createObjectURL(blob);
    
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const image = new Image();
        image.onload = () => { URL.revokeObjectURL(objectUrl); resolve(image); };
        image.onerror = (err) => { URL.revokeObjectURL(objectUrl); reject(err); };
        image.src = objectUrl;
    });

    let { width, height } = img;
    if (width > maxSide || height > maxSide) {
        if (width > height) {
            height = Math.round((height * maxSide) / width);
            width = maxSide;
        } else {
            width = Math.round((width * maxSide) / height);
            height = maxSide;
        }
    }

    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext("2d");
    ctx?.drawImage(img, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", 0.8);
};
