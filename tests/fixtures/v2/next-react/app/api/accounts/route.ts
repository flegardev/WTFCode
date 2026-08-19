export async function GET() {
    return Response.json({ accounts: [] });
}

export async function POST(request: Request) {
    return Response.json(await request.json());
}
