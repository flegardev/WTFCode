from fastapi import Depends, FastAPI
from pydantic import BaseModel
import os

app = FastAPI()

class Account(BaseModel):
    name: str

def current_user():
    return os.getenv("AUTH_MODE")

@app.get("/accounts/{account_id}")
async def read_account(account_id: int, user = Depends(current_user)):
    return Account(name=f"Account {account_id}")
