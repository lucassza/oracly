export interface FixturesFetchErrorDetails {
  date: string;
  url: string;
  httpStatus?: number;
  apiSuccess?: boolean;
  bodyPreview?: string;
}

export class FixturesFetchError extends Error {
  readonly details: FixturesFetchErrorDetails;

  constructor(message: string, details: FixturesFetchErrorDetails) {
    super(message);
    this.name = 'FixturesFetchError';
    this.details = details;
  }
}
